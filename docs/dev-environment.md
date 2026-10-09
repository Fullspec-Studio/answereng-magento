# Local development environment

Everything here runs on one Mac next to other Docker projects (a ddev router
owns 127.0.0.1:80/443), so nothing below binds 80/443 or touches other
containers.

| What | Where |
|---|---|
| Local Magento 2.4.8-p5 (storefront + admin) | http://localhost:8894/ and http://localhost:8894/admin (admin / `admin123!local`) |
| Second website's view (`second_view`) | base URL http://127.0.0.1:8894/ (same nginx; only its base URL host matters for the default store key) |
| Mock engine (`dev/mock-engine`) | http://localhost:8895, reached from the containers as http://host.docker.internal:8895 |
| Test MariaDB for the `Db` suite | 127.0.0.1:3307, container `answereng-mage-testdb` |

The Magento project lives OUTSIDE this repo at
`/Volumes/Tukar/Code/answer-eng/magento-local` and is never committed.

## 1. Test database (Db suite)

From Task 1:

```bash
docker run -d --name answereng-mage-testdb -p 3307:3306 \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=answereng_test mariadb:10.6

PATH=/opt/homebrew/opt/php@8.3/bin:$PATH \
ANSWERENG_TEST_DB_DSN='mysql:host=127.0.0.1;port=3307;dbname=answereng_test' \
ANSWERENG_TEST_DB_USER=root ANSWERENG_TEST_DB_PASSWORD=root vendor/bin/phpunit --testsuite Db
```

## 2. Mock engine

```bash
/usr/bin/python3 dev/mock-engine/mock_engine.py --host 0.0.0.0 --port 8895 --store-cap 3
curl -s -X POST localhost:8895/__mode -d '{"mode":"store-limit"}'   # switch failure modes
```

`--host 0.0.0.0` is required so the PHP container reaches it through
`host.docker.internal`. Use `/usr/bin/python3` (a pyenv `python3` shim may
refuse `-m`/`-c`). State is in memory: restarting the mock forgets every store
(useful for the 404 `unknown_store` cases). Received catalogs land in
`dev/mock-engine/received/` (git-ignored). Stop it with `pkill -f mock_engine.py`.

## 3. Local Magento (docker compose, markshust images, port 8894)

markshust/docker-magento's own compose publishes 80/443, so its images are
used with a small compose file of our own. Images: `markoshust/magento-php:8.3-fpm-7`,
`markoshust/magento-nginx:1.28-0`, `mariadb:11.4`, `markoshust/magento-opensearch:2.12-0`.

`magento-local/compose.yaml`:

```yaml
name: answereng-mage-local

services:
  app:
    image: markoshust/magento-nginx:1.28-0
    ports:
      - "127.0.0.1:8894:8000"
    volumes:
      - ./src:/var/www/html
      - ./conf/default.conf:/etc/nginx/conf.d/default.conf:ro
      - sockdata:/sock
    depends_on: [phpfpm]

  phpfpm:
    image: markoshust/magento-php:8.3-fpm-7
    volumes:
      - ./src:/var/www/html
      - ./composer-cache:/var/www/.composer/cache
      - sockdata:/sock
      # the module, at the same absolute path, for the Composer path repository
      - /Volumes/Tukar/Code/answer-eng/answereng-magento:/Volumes/Tukar/Code/answer-eng/answereng-magento
    environment:
      COMPOSER_DISABLE_XDEBUG_WARN: "1"
    extra_hosts:
      - "host.docker.internal:host-gateway"

  db:
    image: mariadb:11.4
    command:
      --max_allowed_packet=64M
      --optimizer_use_condition_selectivity=1
      --optimizer_switch="rowid_filter=off"
      --log_bin_trust_function_creators=1
    environment:
      MYSQL_ROOT_PASSWORD: magento
      MYSQL_DATABASE: magento
      MYSQL_USER: magento
      MYSQL_PASSWORD: magento
    volumes:
      - dbdata:/var/lib/mysql

  opensearch:
    image: markoshust/magento-opensearch:2.12-0
    environment:
      - "discovery.type=single-node"
      - "cluster.routing.allocation.disk.threshold_enabled=false"
      - "index.blocks.read_only_allow_delete"
      - "DISABLE_SECURITY_PLUGIN=true"
      - "OPENSEARCH_JAVA_OPTS=-Xms512m -Xmx512m"

volumes:
  dbdata:
  sockdata:
```

`magento-local/conf/default.conf` (plain HTTP on 8000; the image's default
redirects to HTTPS). php-fpm in this image listens on `/sock/phpfpm.sock`,
hence the shared `sockdata` volume:

```nginx
upstream fastcgi_backend {
  server unix:/sock/phpfpm.sock;
}

server {
  listen 8000;
  server_name _;
  set $MAGE_ROOT /var/www/html;
  set $MAGE_MODE developer;
  fastcgi_buffer_size 64k;
  fastcgi_buffers 8 128k;
  include /var/www/html/nginx[.]conf;
}
```

Commands that worked (2026-10-08), from `magento-local/`:

```bash
mkdir -p src conf composer-cache
docker compose up -d

# Magento Open Source from the Mage-OS mirror (no Adobe keys)
docker compose exec -T phpfpm composer create-project --no-interaction \
  --repository-url=https://mirror.mage-os.org/ magento/project-community-edition=2.4.8-p5 .

docker compose exec -T phpfpm bin/magento setup:install \
  --base-url=http://localhost:8894/ --db-host=db --db-name=magento --db-user=magento --db-password=magento \
  --admin-firstname=Local --admin-lastname=Admin --admin-email=admin@example.com \
  --admin-user=admin --admin-password='admin123!local' \
  --language=en_US --currency=USD --timezone=UTC --use-rewrites=1 --backend-frontname=admin \
  --search-engine=opensearch --opensearch-host=opensearch --opensearch-port=9200 \
  --opensearch-index-prefix=magento2 --cleanup-database

cp src/nginx.conf.sample src/nginx.conf      # the vhost includes nginx.conf
docker compose restart app

docker compose exec -T phpfpm bash -c '
  bin/magento module:disable Magento_AdminAdobeImsTwoFactorAuth Magento_TwoFactorAuth
  bin/magento deploy:mode:set developer
  bin/magento sampledata:deploy                     # sample data from the same mirror
  bin/magento module:enable $(bin/magento module:status --disabled | grep SampleData | tr "\n" " ")
  bin/magento setup:upgrade && bin/magento indexer:reindex && bin/magento cache:flush'
```

`sampledata:deploy` left the `*SampleData` modules disabled, hence the explicit
`module:enable` before `setup:upgrade` (result: 1,891 simple, 147 configurable,
1 bundle, 1 grouped product).

Admin conveniences for scripted testing (local only):

```bash
docker compose exec -T phpfpm bash -c '
  bin/magento config:set admin/security/use_form_key 0          # no secret key in admin URLs (form keys still enforced on POST)
  bin/magento config:set admin/security/session_lifetime 86400
  bin/magento config:set admin/security/admin_account_sharing 1'
```

### Install the module (Composer path repository)

```bash
docker compose exec -T phpfpm bash -c '
  composer config repositories.answereng "{\"type\":\"path\",\"url\":\"/Volumes/Tukar/Code/answer-eng/answereng-magento\",\"options\":{\"symlink\":false}}"
  composer require --no-interaction fullspec/module-answereng:@dev
  bin/magento module:enable Fullspec_AnswerEng
  bin/magento setup:upgrade
  bin/magento cache:flush'
```

`"symlink": false` (copy mode) is required: with the default symlink, Magento
2.4.8 refuses to render the module's admin template from a real path outside
`/var/www/html` ("Path ... cannot be used with directory /var/www/html/"),
and `dev/template/allow_symlink` does not lift that check. Copy mode mirrors
the repo's working tree, including its dev `vendor/` (~565 MB, harmless).

After editing the module, refresh the copy (faster than `composer reinstall
fullspec/module-answereng`, which re-copies `vendor/`):

```bash
rsync -a --delete --exclude .git --exclude vendor --exclude dist --exclude dev/mock-engine/received \
  /Volumes/Tukar/Code/answer-eng/answereng-magento/ src/vendor/fullspec/module-answereng/
docker compose exec -T phpfpm bash -c 'rm -rf generated/code/Fullspec generated/metadata/* && bin/magento cache:flush'
```

### Production-mode compile and release-zip checks (final review #1, #2)

The copy-mode install mirrors the repo's dev `vendor/` into
`src/vendor/fullspec/module-answereng/vendor`; `setup:di:compile` scans it and
fatals ("Cannot declare trait Aws\Api\ErrorParser\JsonParserTrait"). Delete that
copy before compiling (the refresh rsync excludes `vendor`, so it stays gone):

```bash
rm -rf src/vendor/fullspec/module-answereng/vendor
docker compose exec -T phpfpm bash -c '
  bin/magento setup:di:compile
  grep -l __set_state generated/metadata/*.php          # expect nothing
  bin/magento deploy:mode:set production --skip-compilation'
# load http://localhost:8894/ and the AnswerEng section at default + store-view scope,
# run dev/e2e/cron.sh, check var/log/exception.log, then:
docker compose exec -T phpfpm bash -c '
  bin/magento deploy:mode:set developer
  rm -rf generated/code/* generated/metadata/* && bin/magento cache:flush'
```

Release zip, installed exactly as the README says (the zip archives HEAD, so
commit first). Back up the root `composer.json`/`composer.lock`, which the
artifact install rewrites:

```bash
(cd ../answereng-magento && bin/build-zip.sh 0.1.0)
mkdir -p src/var/answereng-artifact && cp ../answereng-magento/dist/answereng-magento-0.1.0.zip src/var/answereng-artifact/
cp src/composer.json /tmp/composer.json.bak && cp src/composer.lock /tmp/composer.lock.bak
docker compose exec -T phpfpm bash -c '
  composer config repositories.answereng artifact /var/www/html/var/answereng-artifact
  composer require --no-interaction fullspec/module-answereng     # "Using version ^0.1.0"
  bin/magento module:enable Fullspec_AnswerEng && bin/magento setup:upgrade'
# restore the path-repo install
cp /tmp/composer.json.bak src/composer.json && cp /tmp/composer.lock.bak src/composer.lock
docker compose exec -T phpfpm bash -c '
  composer install --no-interaction && rm -rf vendor/fullspec/module-answereng/vendor
  bin/magento setup:upgrade'
rm -rf src/var/answereng-artifact
```

### Configure

Stores › Configuration › Catalog › AnswerEng:
- Default scope: Engine URL `http://host.docker.internal:8895`, API key `ae_test`
  (any string: the mock derives a fake account from it; `ae_other` = a second
  workspace), Brand names `Luma`, Competitors `Lululemon` / `Nike`.
- Store view `default`: Enabled = Yes, Store key blank → saved as `localhost`.
- Store view `second_view`: Enabled = Yes, Store key blank → saved as `127.0.0.1`.

The second website (`second`, group `second_store`, view `second_view`, base
URL `http://127.0.0.1:8894/`, 32 visible sample products also assigned to it)
was created with `dev/e2e/create-second-website.php`
(StoreFactory/GroupFactory/WebsiteFactory + base URL config).

### Verification gates

```bash
docker compose exec -T phpfpm bin/magento module:status Fullspec_AnswerEng   # Module is enabled
docker compose exec -T phpfpm bin/magento setup:db:status                    # All modules are up to date.
docker compose exec -T db mariadb -umagento -pmagento magento -e "show tables like 'answereng%'"   # 4 tables
docker compose exec -T phpfpm bin/magento cron:run                           # cron_schedule: answereng_sync_dirty success
```

### Cron

No system crontab is installed, so nothing runs behind your back during
tests. Run jobs with `bin/magento cron:run --group=default`. Magento drops a
pending `cron_schedule` row whose time does not match the job's expression,
so `dev/e2e/cron.sh` queues `answereng_sync_dirty` on its latest
`*/5` slot, and for `answereng_reconcile` temporarily overrides
`crontab/default/jobs/answereng_reconcile/schedule/cron_expr` to `* * * * *`
for that one run. To run cron continuously instead:

```bash
while true; do docker compose exec -T phpfpm bin/magento cron:run; sleep 60; done
```

### Helper scripts (`dev/e2e/`)

The checklist helpers are committed under `dev/e2e/` (see its README; local
only, no real keys): `admin.py` (admin session + config save + panel action
POSTs over HTTP), `sql.sh`, `mage.sh`, `state.sh` (sync state, log,
registry), `cron.sh`, `refresh-module.sh`, `archive-received.sh`, `row28.py`,
and PHP scripts to copy into `src/var/scripts/`: `product.php` (save via
ProductRepository), `mass-status.php` (Product\Action::updateAttributes, the
admin mass update), `import.php` (ImportExport Import model),
`acl-users.php` (limited roles `aesync` = config+sync, `aeconfig` = config
only), `create-second-website.php`. The shell scripts locate this compose
project through `MAGENTO_LOCAL` (default
`/Volumes/Tukar/Code/answer-eng/magento-local`). They started life in
`magento-local/scripts/`, where older notes refer to them.

## 4. Reset

- Module code only, keep data: `composer remove fullspec/module-answereng` +
  `bin/magento setup:upgrade` (`module:uninstall --non-composer` is not for
  path installs). Executed equivalent: `bin/magento module:uninstall
  Fullspec_AnswerEng` WITHOUT `--remove-data` removed the code but left the
  four tables and the 8 `answereng/*` config rows, as Magento documents.
- Full clean uninstall (tables + `answereng/*` config rows):

  ```bash
  echo y | docker compose exec -T phpfpm bin/magento module:uninstall --remove-data Fullspec_AnswerEng
  ```

  This runs `Setup\Uninstall` (needs the `setup_version` in `etc/module.xml`),
  removes the module from `setup_module` and `config.php`, and runs
  `composer remove`. Reinstall with the commands under "Install the module".
- Local sync state only: `TRUNCATE` the four `answereng_*` tables.
- Whole store: `docker compose down -v` (drops the DB volume), then empty `src/`.

## 5. Start / stop

```bash
cd /Volumes/Tukar/Code/answer-eng/magento-local
docker compose up -d        # start
docker compose stop         # stop, keep data
docker compose down         # remove containers, keep the dbdata volume
pkill -f mock_engine.py     # stop the mock engine
```
