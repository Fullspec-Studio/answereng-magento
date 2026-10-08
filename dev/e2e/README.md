# End-to-end helpers (local only)

Scripts used to run `docs/integration-checklist.md` against the local Magento 2.4.8
stack described in `docs/dev-environment.md` (storefront/admin on
http://localhost:8894, mock engine on http://localhost:8895). They drive a
throwaway local install only: the admin password `admin123!local` and the
`aesync` / `aeconfig` role passwords below are local-only test values, and the
API keys the checklist uses (`ae_test`, `ae_other`) are mock-engine strings, not
real AnswerEng keys. Never point these scripts at a real store or engine.

The shell scripts find the Magento compose project through `MAGENTO_LOCAL`
(default `/Volumes/Tukar/Code/answer-eng/magento-local`) and the module repo
from their own location.

| Script | Use |
|---|---|
| `mage.sh <args>` | `bin/magento` inside the phpfpm container (`mage.sh cron:run`). |
| `sql.sh "<sql>"` | SQL against the local Magento DB. |
| `state.sh [n]` | AnswerEng sync state, the last `n` sync-log rows (default 6), the remote-store registry. |
| `cron.sh [job]` | Run `answereng_sync_dirty` (default) or `answereng_reconcile` now through the real cron runner (queues the job on a slot Magento accepts). |
| `refresh-module.sh` | Re-copy the repo into the copy-mode install (`src/vendor/fullspec/module-answereng`) and flush generated code + cache. |
| `archive-received.sh <label>` | Move the mock engine's received payloads to `$MAGENTO_LOCAL/received-archive/<label>/`. |
| `admin.py` | Python admin HTTP client: login, config page at a scope, real config-form saves, panel action POSTs with the page's form key (`from admin import Admin`). Cookie jars go to `dev/e2e/.jars/` (git-ignored). |
| `row28.py` | Checklist row 28: GET and form-key-less POSTs to every `answereng/action/*` route. |

PHP scripts run inside the container; copy them first:

```bash
mkdir -p "$MAGENTO_LOCAL/src/var/scripts" && cp dev/e2e/*.php "$MAGENTO_LOCAL/src/var/scripts/"
docker compose -f "$MAGENTO_LOCAL/compose.yaml" exec -T phpfpm php var/scripts/product.php 24-MB01 name=Edited
```

| Script | Use |
|---|---|
| `create-second-website.php` | Website `second` / view `second_view` (base URL http://127.0.0.1:8894/) with 40 products. |
| `product.php <sku> [k=v ...]` | Save a product through `ProductRepository` (`websites=1,2`, `status=2`, `name=…`). |
| `product-delete.php <sku> [sku …]` | Delete products through `ProductRepository` (the delete events). |
| `category-save.php <categoryId> [name]` | Save a category through `CategoryRepository` (the category save events). |
| `mass-status.php <storeId> <1\|2> [all\|sku,…]` | `Product\Action::updateAttributes` (the admin mass update). |
| `import.php <csv> [append\|replace\|delete]` | Product CSV import through the ImportExport `Import` model. |
| `acl-users.php` | Limited roles: `aesync` / `Sync1234!local` (config + sync), `aeconfig` / `Conf1234!local` (config only). |

Use `/usr/bin/python3` (a pyenv `python3` shim may refuse `-m`/`-c`), from this
directory so `import admin` resolves:

```bash
cd dev/e2e && /usr/bin/python3 -c "from admin import Admin; a = Admin('admin', 'admin123!local'); a.login(); print(len(a.config(store=1)))"
```
