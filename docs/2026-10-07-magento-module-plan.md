# AnswerEng Magento Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `Fullspec_AnswerEng`, a Magento 2.4 module that syncs each opted-in store view's catalog to AnswerEng (debounced full-replace push, nightly reconcile), shows sync status and a headline visibility number in admin, and lets an authorized admin clear, delete (7-day undo) or forget remote stores safely.

**Architecture:** Core logic is plain PHP behind small ports — `Transport` (HTTP), `SqlExecutor` (database), `CatalogSource` (product data), `SettingsProvider` (config), `SyncLock`, `Clock`, `KeyValueCache`, `ConfigDisabler` — with thin Magento adapters. The sync state machine, logs and remote-store registry are SQL repositories tested against a real MariaDB (no Magento boot). Magento-facing code (config, observers, cron, CLI, admin UI) is thin and covered by PHPStan, phpcs and the scripted end-to-end checklist against a local Magento and a mock engine.

**Tech Stack:** PHP 8.1–8.4, Magento Open Source / Adobe Commerce 2.4.6–2.4.8 (packages from the Mage-OS mirror), Guzzle 7 (ships with Magento), PHPUnit 10, magento/magento-coding-standard, PHPStan + bitexpert/phpstan-magento, MariaDB 10.6 for repository tests, Python 3 stdlib for the mock engine.

**Spec:** `/Volumes/Tukar/Code/answer-eng/docs/specs/2026-10-05-magento-connector-design.md` — §2–§10 are this module; §11 is the engine contract it consumes (implemented in engine PR #17, branch `feat/connector-lifecycle`). Read §3, §4, §6 and §7.1 before starting.

## Global Constraints

- Module `Fullspec_AnswerEng`; Composer `fullspec/module-answereng`, type `magento2-module`, license `["OSL-3.0", "AFL-3.0"]`; PSR-4 root `Fullspec\AnswerEng\` → repo root.
- Repo: `/Volumes/Tukar/Code/answer-eng/answereng-magento` (GitHub `Fullspec-Studio/answereng-magento`, private).
- PHP `~8.1.0||~8.2.0||~8.3.0||~8.4.0`; Magento 2.4.6–2.4.8 (`magento/framework ^103.0.6`). Magento packages come from `https://mirror.mage-os.org/` — never require Adobe Marketplace keys.
- Engine contract: `POST /catalog`, `DELETE /stores/{key}/catalog`, `DELETE /stores/{key}` (body `{"confirm_store_key": key}`), `POST /stores/{key}/restore`, `GET /stores`, `GET /stores/{key}/summary`; header `x-api-key`; response header `X-AnswerEng-Account: acct_<32 hex>`. Branch only on the body's `code`, never on `error` text.
- Error codes: `invalid_key`, `store_limit`, `product_limit`, `store_owned_elsewhere`, `validation_failed`, `unknown_store`, `run_in_progress`, `store_pending_deletion`, `confirmation_mismatch`, `purge_in_progress`, `invalid_request`, `throttled`, `quota_exceeded`.
- Hosted default engine URL `https://api.answereng.io`, applied at request time, never persisted.
- Catalog payload key order: top `store_key, brand_names, competitors, products`; product `sku, title, description, price, category, attributes`; empty `attributes` encodes as `{}`. At least 1 and at most 5,000 products.
- Debounce 300 s; reconcile nightly; transient retries at +5 min then +15 min, `exhausted` on the 3rd consecutive transient failure; 429 waits `max(Retry-After, 300 s)`; sync log keeps the last 20 entries per view; summary cache 15 min.
- States: `ok`, `retrying`, `exhausted`, `blocked`, `paused`. Catalog-dependent paused reasons: `validation_failed`, `product_limit`.
- ACL: `Fullspec_AnswerEng::config`, `Fullspec_AnswerEng::sync`, `Fullspec_AnswerEng::delete_store`.
- Never store or log the API key or a raw engine response body; stored error detail is status + code (+ limit) and, for 422, at most 5 `loc`/`type` pairs (≤200 chars each, ≤1,000 total).
- Timestamps are UTC `Y-m-d H:i:s` produced by the `Clock` port — never SQL `NOW()`.
- Local ports: Magento storefront 8894; test MariaDB 3307; mock engine 8895 (8888/8892/8893 belong to other projects).
- Commands: `vendor/bin/phpunit --testsuite Unit`; `vendor/bin/phpunit --testsuite Db` (needs `ANSWERENG_TEST_DB_DSN`, `ANSWERENG_TEST_DB_USER`, `ANSWERENG_TEST_DB_PASSWORD`); `vendor/bin/phpcs`; `vendor/bin/phpstan analyse`.
- No engine/AWS calls outside the mock engine during development. The live smoke test (spec §9) is a separate, owner-confirmed step after engine PR #17 deploys.
- Commits: `git add` explicit paths only; messages are `git commit -m "<subject>" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`.

## Review Focus

1. **Store keys typed with stray whitespace or uppercase** — the key is trimmed, never case-folded (the engine matches exactly), and uniqueness compares the trimmed values. Test owned by Task 7.
2. **Product text with HTML entities, emoji or invalid UTF-8** — JSON encoding must never throw or drop the product; invalid bytes are substituted. Test owned by Task 8.
3. **Price index missing for a website (not yet reindexed)** — `price` is `null`, the push still happens. Test owned by Task 8 (builder) — the adapter returns null for missing rows.
4. **API key blank** — the module never contacts the engine; the view is `blocked` with reason `no_api_key`. Test owned by Task 10.
5. **Engine returns a non-JSON body (HTML 502 page)** — classified transient by status, no exception. Test owned by Task 3.

---

## File Structure

| Path | Responsibility |
|---|---|
| `registration.php`, `etc/module.xml`, `composer.json` | Module identity |
| `Model/Engine/` | `HttpResponse`, `Transport`, `TransportException`, `GuzzleTransport`, `Connection`, `EngineResult`, `EngineClient`, `ErrorCode` |
| `Model/Sync/` | `Outcome`, `OutcomeKind`, `Classifier`, `ErrorDetail`, `SyncState`, `SyncStateRepository`, `SyncLog`, `Syncer`, `SyncResult`, `ChangeTracker`, `ConfigChangeHandler` |
| `Model/Db/` | `SqlExecutor` port, `MagentoSqlExecutor` |
| `Model/Catalog/` | `ProductRecord`, `CatalogSource` port, `ExportResult`, `TextCleaner`, `CatalogBuilder`, `MagentoCatalogSource` |
| `Model/Config/` | `Settings`, `SettingsProvider` port, `ConfigSettingsProvider`, `StoreKeyPolicy`, `Backend/StoreKey`, `StoreKeyComment`, `ConfigDisabler` port + `MagentoConfigDisabler` |
| `Model/Registry/` | `RemoteStore`, `RemoteStoreRegistry`, `RoleResolver` |
| `Model/Admin/` | `RemoteStoreActions`, `ConnectionReport`, `ActionResult`, `SummaryProvider`, `SummaryView`, `KeyValueCache` port + `MagentoKeyValueCache`, `PanelViewModel` |
| `Model/Support/` | `Clock` port + `SystemClock`, `SyncLock` port + `MagentoSyncLock` |
| `Observer/` | `ProductSaveBefore`, `ProductSaveAfter`, `ProductDeleteBefore`, `ProductDeleteAfter`, `MarkAllDirty`, `ConfigSaved` |
| `Cron/` | `SyncDirty`, `Reconcile` |
| `Console/Command/Sync.php` | `bin/magento answereng:sync` |
| `Block/Adminhtml/System/Config/StatusPanel.php` + `view/adminhtml/templates/status.phtml` + `view/adminhtml/web/js/panel-actions.js` | Admin panel |
| `Controller/Adminhtml/Action/*` | Sync now, Test connection, Clear, Delete, Cancel, Forget |
| `Setup/Uninstall.php` | Clean uninstall |
| `etc/*.xml`, `etc/db_schema.xml`, `etc/db_schema_whitelist.json` | Wiring and schema |
| `Test/Unit/`, `Test/Db/`, `Test/Fixtures/` | Unit tests, MariaDB repository tests, frozen engine contract |
| `dev/mock-engine/` | Python mock engine for local end-to-end testing |
| `docs/` | Spec + plan copies, dev environment, integration checklist |

---

### Task 1: Repository scaffold, tooling and contract fixtures

**Files:**
- Create: `composer.json`, `registration.php`, `etc/module.xml`, `phpunit.xml.dist`, `phpcs.xml.dist`, `phpstan.neon.dist`, `.gitignore`, `.gitattributes`, `.github/workflows/ci.yml`, `README.md`
- Create: `Test/Fixtures/` (copied verbatim from the engine), `Test/Unit/ContractTest.php`
- Create: `docs/2026-10-05-magento-connector-design.md`, `docs/2026-10-07-magento-module-plan.md` (copies)
- Create (untracked, ignored globally): `CLAUDE.md`

**Interfaces:**
- Produces: an installable, testable empty module; `Test/Fixtures/{catalog_push.json,error_codes.json,read_api/*.json,CONTRACT.sha256}`; test suites `Unit` and `Db`.

- [ ] **Step 1: Create the repository and copy the spec and plan**

```bash
mkdir -p /Volumes/Tukar/Code/answer-eng/answereng-magento
cd /Volumes/Tukar/Code/answer-eng/answereng-magento
git init -b main
mkdir -p docs Test/Fixtures/read_api Test/Unit Test/Db etc
cp ../docs/specs/2026-10-05-magento-connector-design.md docs/
cp ../docs/plans/2026-10-07-magento-module.md docs/2026-10-07-magento-module-plan.md
```

- [ ] **Step 2: Copy the frozen engine contract**

The contract lives on engine branch `feat/connector-lifecycle` (PR #17). Copy it byte-for-byte:

```bash
src=/Volumes/Tukar/Code/answer-eng/.worktrees/engine-lifecycle/tests/fixtures
cp "$src/catalog_push.json" "$src/error_codes.json" "$src/CONTRACT.sha256" Test/Fixtures/
cp "$src"/read_api/*.json Test/Fixtures/read_api/
```

If that worktree no longer exists, use `git -C /Volumes/Tukar/Code/answer-eng/answereng-engine show origin/feat/connector-lifecycle:tests/fixtures/<file>` (or `origin/main` once PR #17 is merged) for each file.

- [ ] **Step 3: Write `composer.json`**

```json
{
    "name": "fullspec/module-answereng",
    "description": "AnswerEng for Magento: sync your catalog to AnswerEng and see how AI shopping assistants recommend your store.",
    "type": "magento2-module",
    "license": ["OSL-3.0", "AFL-3.0"],
    "require": {
        "php": "~8.1.0||~8.2.0||~8.3.0||~8.4.0",
        "ext-json": "*",
        "guzzlehttp/guzzle": "^7.5",
        "magento/framework": "^103.0.6",
        "magento/module-backend": "*",
        "magento/module-catalog": "*",
        "magento/module-config": "*",
        "magento/module-cron": "*",
        "magento/module-store": "*"
    },
    "require-dev": {
        "bitexpert/phpstan-magento": "^0.30",
        "magento/magento-coding-standard": "^33",
        "phpstan/extension-installer": "^1.3",
        "phpstan/phpstan": "^1.10",
        "phpunit/phpunit": "^10.5"
    },
    "repositories": [
        {"type": "composer", "url": "https://mirror.mage-os.org/", "only": ["magento/*"]}
    ],
    "autoload": {
        "files": ["registration.php"],
        "psr-4": {"Fullspec\\AnswerEng\\": ""}
    },
    "autoload-dev": {
        "psr-4": {"Fullspec\\AnswerEng\\Test\\": "Test/"}
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true,
            "laminas/laminas-dependency-plugin": false,
            "magento/composer-dependency-version-audit-plugin": false,
            "magento/magento-composer-installer": false,
            "phpstan/extension-installer": true
        }
    },
    "scripts": {
        "test": "phpunit --testsuite Unit",
        "lint": "phpcs",
        "analyse": "phpstan analyse --no-progress"
    }
}
```

- [ ] **Step 4: Module registration**

`registration.php`:

```php
<?php
/**
 * AnswerEng for Magento.
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Fullspec_AnswerEng', __DIR__);
```

`etc/module.xml`:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Module/etc/module.xsd">
    <module name="Fullspec_AnswerEng">
        <sequence>
            <module name="Magento_Store"/>
            <module name="Magento_Catalog"/>
            <module name="Magento_Config"/>
            <module name="Magento_Backend"/>
            <module name="Magento_Cron"/>
        </sequence>
    </module>
</config>
```

- [ ] **Step 5: Tooling configuration**

`phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="Test/bootstrap.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>Test/Unit</directory>
        </testsuite>
        <testsuite name="Db">
            <directory>Test/Db</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

`phpcs.xml.dist`:

```xml
<?xml version="1.0"?>
<ruleset name="AnswerEng">
    <rule ref="Magento2"/>
    <arg name="extensions" value="php,phtml"/>
    <file>registration.php</file>
    <file>Block</file>
    <file>Console</file>
    <file>Controller</file>
    <file>Cron</file>
    <file>Model</file>
    <file>Observer</file>
    <file>Setup</file>
    <file>view</file>
    <exclude-pattern>*/vendor/*</exclude-pattern>
</ruleset>
```

`Test/bootstrap.php` — Magento's translation function `__()` is defined in a real installation's `app/functions.php`, which is not part of `magento/framework`, so tests and PHPStan need this shim:

```php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

if (!function_exists('__')) {
    /**
     * Mirrors Magento's app/functions.php for tests and static analysis.
     *
     * @param mixed ...$args
     */
    function __(...$args): \Magento\Framework\Phrase
    {
        $text = array_shift($args);
        if (isset($args[0]) && is_array($args[0])) {
            $args = $args[0];
        }
        return new \Magento\Framework\Phrase((string) $text, $args);
    }
}
```

`phpstan.neon.dist`:

```neon
parameters:
    level: 6
    bootstrapFiles:
        - Test/bootstrap.php
    paths:
        - Block
        - Console
        - Controller
        - Cron
        - Model
        - Observer
        - Setup
```

`.gitignore`:

```
/vendor/
/composer.lock.bak
/.phpunit.cache/
/dev/mock-engine/received/
/dist/
.DS_Store
```

`.gitattributes` (keeps the release zip to module files only):

```
/Test export-ignore
/dev export-ignore
/docs export-ignore
/.github export-ignore
/bin export-ignore
/phpunit.xml.dist export-ignore
/phpcs.xml.dist export-ignore
/phpstan.neon.dist export-ignore
/.gitattributes export-ignore
/.gitignore export-ignore
```

- [ ] **Step 6: Write the failing contract test**

`Test/Unit/ContractTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Test/Fixtures/* is copied byte-for-byte from the engine (tests/fixtures) and
 * pinned by CONTRACT.sha256, the same manifest every connector carries.
 */
final class ContractTest extends TestCase
{
    public function testFixturesMatchTheManifest(): void
    {
        $dir = dirname(__DIR__) . '/Fixtures';
        $lines = file($dir . '/CONTRACT.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertNotEmpty($lines);
        foreach ($lines as $line) {
            [$hash, $relative] = preg_split('/\s+/', trim($line), 2);
            self::assertFileExists($dir . '/' . $relative);
            self::assertSame($hash, hash_file('sha256', $dir . '/' . $relative), $relative);
        }
    }

    public function testManifestCoversTheErrorContract(): void
    {
        $manifest = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/CONTRACT.sha256');
        self::assertStringContainsString('error_codes.json', $manifest);
        self::assertStringContainsString('read_api/stores.json', $manifest);
    }
}
```

- [ ] **Step 7: Install and run**

```bash
composer install --no-interaction
vendor/bin/phpunit --testsuite Unit
```

Expected: 2 tests pass (the fixtures are already in place, so this test goes green immediately — its job is to guard future drift). If `composer install` asks about a plugin not listed in `allow-plugins`, add it with `false` and rerun.

- [ ] **Step 8: Local MariaDB for repository tests**

```bash
docker run -d --name answereng-mage-testdb -p 3307:3306 \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=answereng_test mariadb:10.6
```

Record in `README.md` (Step 10) that Db tests use:
`ANSWERENG_TEST_DB_DSN='mysql:host=127.0.0.1;port=3307;dbname=answereng_test' ANSWERENG_TEST_DB_USER=root ANSWERENG_TEST_DB_PASSWORD=root`.

- [ ] **Step 9: CI workflow**

`.github/workflows/ci.yml`:

```yaml
name: CI
on:
  push:
  pull_request:
jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.1', '8.3']
    services:
      mariadb:
        image: mariadb:10.6
        env:
          MARIADB_ROOT_PASSWORD: root
          MARIADB_DATABASE: answereng_test
        ports: ['3306:3306']
        options: >-
          --health-cmd="healthcheck.sh --connect --innodb_initialized"
          --health-interval=5s --health-timeout=5s --health-retries=20
    env:
      ANSWERENG_TEST_DB_DSN: mysql:host=127.0.0.1;port=3306;dbname=answereng_test
      ANSWERENG_TEST_DB_USER: root
      ANSWERENG_TEST_DB_PASSWORD: root
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: bcmath, ctype, curl, dom, gd, intl, mbstring, pdo_mysql, soap, sodium, xsl, zip
          tools: composer:v2
      - uses: actions/cache@v4
        with:
          path: vendor
          key: vendor-${{ matrix.php }}-${{ hashFiles('composer.lock') }}
      - run: composer validate --strict
      - run: composer install --no-interaction --no-progress
      - run: vendor/bin/phpunit
      - run: vendor/bin/phpcs
      - run: vendor/bin/phpstan analyse --no-progress
```

- [ ] **Step 10: README and CLAUDE.md**

`README.md`:

```markdown
# AnswerEng for Magento

Magento 2.4 module (`Fullspec_AnswerEng`) that syncs each opted-in store view's
catalog to [AnswerEng](https://answereng.io) and shows sync status and a
headline AI-visibility number in admin.

## Development

- `composer install` (Magento packages come from the Mage-OS mirror; no Adobe keys).
- Unit tests: `vendor/bin/phpunit --testsuite Unit`
- Repository tests need MariaDB (see `docker run … answereng-mage-testdb` in
  docs/dev-environment.md):
  `ANSWERENG_TEST_DB_DSN='mysql:host=127.0.0.1;port=3307;dbname=answereng_test' ANSWERENG_TEST_DB_USER=root ANSWERENG_TEST_DB_PASSWORD=root vendor/bin/phpunit --testsuite Db`
- Lint and static analysis: `vendor/bin/phpcs`, `vendor/bin/phpstan analyse`.
- `Test/Fixtures/` is the frozen engine contract; never edit it by hand —
  copy it from the engine repo together with `CONTRACT.sha256`.

Design: docs/2026-10-05-magento-connector-design.md.
```

`CLAUDE.md` (do NOT `git add` it — the user's global gitignore excludes it):

```markdown
# answereng-magento

Magento 2.4 module Fullspec_AnswerEng (AnswerEng catalog connector). Read
docs/2026-10-05-magento-connector-design.md (§2–§10 module, §11 engine contract)
and docs/2026-10-07-magento-module-plan.md before changing anything.

## Load-bearing conventions
- Core logic is plain PHP behind ports (Transport, SqlExecutor, CatalogSource,
  SettingsProvider, SyncLock, Clock, KeyValueCache, ConfigDisabler); Magento
  adapters stay thin.
- Branch only on the engine's `code`, never its `error` text. Never log the API
  key or raw response bodies.
- Test/Fixtures is the frozen engine contract (CONTRACT.sha256) — copy from the
  engine, never hand-edit.
- SQL uses Clock-provided UTC timestamps, never NOW(). MySQL evaluates UPDATE
  SET assignments left to right — order matters in SyncStateRepository.
- Ports: storefront 8894, test MariaDB 3307, mock engine 8895.
```

- [ ] **Step 11: Lint, analyse and commit**

```bash
vendor/bin/phpcs
vendor/bin/phpstan analyse --no-progress
composer validate --strict
git add composer.json composer.lock registration.php etc/module.xml phpunit.xml.dist phpcs.xml.dist phpstan.neon.dist Test/bootstrap.php .gitignore .gitattributes .github/workflows/ci.yml README.md Test/Unit/ContractTest.php Test/Fixtures docs/2026-10-05-magento-connector-design.md docs/2026-10-07-magento-module-plan.md
git commit -m "chore: scaffold Fullspec_AnswerEng module with tooling and engine contract" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Expected: phpcs and PHPStan report no errors (PHPStan may report "no files" until code exists — that is fine at this step only).

- [ ] **Step 12: Create the GitHub repository (owner confirmation required)**

This publishes a new private repository. **Ask the owner before running it.**

```bash
gh repo create Fullspec-Studio/answereng-magento --private --source . --remote origin --push
```

---

### Task 2: Engine HTTP layer

**Files:**
- Create: `Model/Engine/HttpResponse.php`, `Model/Engine/Transport.php`, `Model/Engine/TransportException.php`, `Model/Engine/GuzzleTransport.php`, `Model/Engine/Connection.php`, `Model/Engine/EngineResult.php`, `Model/Engine/EngineClient.php`, `Model/Engine/ErrorCode.php`
- Create: `etc/di.xml`
- Test: `Test/Unit/Engine/FakeTransport.php`, `Test/Unit/Engine/EngineClientTest.php`, `Test/Unit/Engine/EngineResultTest.php`, `Test/Unit/Engine/ErrorCodeTest.php`

**Interfaces:**
- Produces: `Transport::send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse` (throws `TransportException`); `Connection(string $engineUrl, string $apiKey)`; `EngineResult` with `status: ?int`, `json: ?array`, `account: ?string`, `retryAfter: ?int`, `transportError: ?string`, methods `isSuccess(): bool`, `code(): ?string`, `limit(): ?int`, `purgeAfter(): ?string`; `EngineClient::postCatalog(Connection, string $json)`, `clearCatalog(Connection, string $storeKey)`, `deleteStore(Connection, string $storeKey)`, `restoreStore(Connection, string $storeKey)`, `listStores(Connection)`, `summary(Connection, string $storeKey)` — all return `EngineResult`; enum `ErrorCode` (string-backed).

- [ ] **Step 1: Write the test doubles and failing tests**

`Test/Unit/Engine/FakeTransport.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Engine;

use Fullspec\AnswerEng\Model\Engine\HttpResponse;
use Fullspec\AnswerEng\Model\Engine\Transport;
use Fullspec\AnswerEng\Model\Engine\TransportException;

/** Records requests and replays queued responses (or TransportException). */
final class FakeTransport implements Transport
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int}> */
    public array $requests = [];
    /** @var list<HttpResponse|TransportException> */
    private array $queue = [];

    public function enqueue(HttpResponse|TransportException $next): self
    {
        $this->queue[] = $next;
        return $this;
    }

    /** @param array<string,mixed>|null $json */
    public static function json(int $status, ?array $json, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, array_change_key_case($headers), $json === null ? '' : json_encode($json));
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers,
            'body' => $body, 'timeout' => $timeoutSeconds];
        if ($this->queue === []) {
            throw new \LogicException("unexpected request $method $url");
        }
        $next = array_shift($this->queue);
        if ($next instanceof TransportException) {
            throw $next;
        }
        return $next;
    }
}
```

`Test/Unit/Engine/EngineResultTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Engine;

use Fullspec\AnswerEng\Model\Engine\EngineResult;
use Fullspec\AnswerEng\Model\Engine\HttpResponse;
use PHPUnit\Framework\TestCase;

final class EngineResultTest extends TestCase
{
    public function testParsesCodeLimitAccountAndRetryAfter(): void
    {
        $r = EngineResult::fromResponse(new HttpResponse(403, [
            'x-answereng-account' => 'acct_' . str_repeat('a', 32),
            'retry-after' => '120',
        ], '{"error":"plan allows 1 store(s)","code":"store_limit","limit":1}'));
        self::assertFalse($r->isSuccess());
        self::assertSame('store_limit', $r->code());
        self::assertSame(1, $r->limit());
        self::assertSame('acct_' . str_repeat('a', 32), $r->account);
        self::assertSame(120, $r->retryAfter);
    }

    public function testNonJsonBodyIsNotAnError(): void
    {
        $r = EngineResult::fromResponse(new HttpResponse(502, [], '<html>Bad Gateway</html>'));
        self::assertSame(502, $r->status);
        self::assertNull($r->json);
        self::assertNull($r->code());
    }

    public function testNonNumericRetryAfterIsIgnored(): void
    {
        $r = EngineResult::fromResponse(new HttpResponse(429, ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT'], '{}'));
        self::assertNull($r->retryAfter);
    }

    public function testTransportFailure(): void
    {
        $r = EngineResult::transportFailure('timed out');
        self::assertNull($r->status);
        self::assertSame('timed out', $r->transportError);
        self::assertFalse($r->isSuccess());
    }
}
```

`Test/Unit/Engine/EngineClientTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Engine;

use Fullspec\AnswerEng\Model\Engine\Connection;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Engine\TransportException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EngineClientTest extends TestCase
{
    private FakeTransport $transport;
    private EngineClient $client;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new EngineClient($this->transport, new NullLogger());
        $this->conn = new Connection('https://engine.test/', 'ae_secret');
    }

    public function testPostCatalogSendsKeyJsonAndTrimsTheBaseUrl(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, ['store_id' => 7]));
        $r = $this->client->postCatalog($this->conn, '{"store_key":"s"}');
        self::assertTrue($r->isSuccess());
        $req = $this->transport->requests[0];
        self::assertSame(['POST', 'https://engine.test/catalog'], [$req['method'], $req['url']]);
        self::assertSame('ae_secret', $req['headers']['x-api-key']);
        self::assertSame('application/json', $req['headers']['Content-Type']);
        self::assertSame('{"store_key":"s"}', $req['body']);
    }

    public function testStoreKeysAreUrlEncodedInPaths(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, []))
            ->enqueue(FakeTransport::json(202, []))
            ->enqueue(FakeTransport::json(200, []));
        $this->client->clearCatalog($this->conn, 'shop.example.com');
        $this->client->deleteStore($this->conn, 'a b/c');
        $this->client->restoreStore($this->conn, 'shop.example.com');
        $urls = array_column($this->transport->requests, 'url');
        self::assertSame([
            'https://engine.test/stores/shop.example.com/catalog',
            'https://engine.test/stores/a%20b%2Fc',
            'https://engine.test/stores/shop.example.com/restore',
        ], $urls);
        self::assertSame('DELETE', $this->transport->requests[1]['method']);
        self::assertSame('{"confirm_store_key":"a b\/c"}', $this->transport->requests[1]['body']);
    }

    public function testGetsRetryOnceOn5xxAndTransportErrors(): void
    {
        $this->transport->enqueue(FakeTransport::json(504, null))
            ->enqueue(FakeTransport::json(200, [['store_key' => 's']]));
        self::assertTrue($this->client->listStores($this->conn)->isSuccess());

        $this->transport->enqueue(new TransportException('reset'))
            ->enqueue(FakeTransport::json(200, ['store_key' => 's']));
        self::assertTrue($this->client->summary($this->conn, 's')->isSuccess());
        self::assertCount(4, $this->transport->requests);
    }

    public function testWritesNeverRetry(): void
    {
        $this->transport->enqueue(FakeTransport::json(504, null));
        self::assertSame(504, $this->client->postCatalog($this->conn, '{}')->status);
        self::assertCount(1, $this->transport->requests);
    }

    public function testTransportErrorBecomesAResult(): void
    {
        $this->transport->enqueue(new TransportException('connection refused'));
        $r = $this->client->postCatalog($this->conn, '{}');
        self::assertSame('connection refused', $r->transportError);
    }
}
```

`Test/Unit/Engine/ErrorCodeTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Engine;

use Fullspec\AnswerEng\Model\Engine\ErrorCode;
use PHPUnit\Framework\TestCase;

final class ErrorCodeTest extends TestCase
{
    public function testEveryEngineCodeIsKnown(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/error_codes.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $codes = array_keys($fixture['lambda']);
        foreach ($fixture['gateway'] as $body) {
            $codes[] = $body['code'];
        }
        foreach (array_unique($codes) as $code) {
            self::assertNotNull(ErrorCode::tryFrom($code), "unknown engine code $code");
        }
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --testsuite Unit --filter 'Engine'`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the engine layer**

`Model/Engine/HttpResponse.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

final class HttpResponse
{
    /**
     * @param array<string,string> $headers header names lower-cased
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
```

`Model/Engine/Transport.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

interface Transport
{
    /**
     * @param array<string,string> $headers
     * @throws TransportException on connection/timeout failures (never on HTTP status)
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse;
}
```

`Model/Engine/TransportException.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

class TransportException extends \RuntimeException
{
}
```

`Model/Engine/GuzzleTransport.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/** Guzzle ships with Magento 2.4 and supports DELETE with a body. */
final class GuzzleTransport implements Transport
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse
    {
        try {
            $response = $this->client->request($method, $url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $timeoutSeconds,
                'connect_timeout' => min(10, $timeoutSeconds),
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
        $lower = [];
        foreach ($response->getHeaders() as $name => $values) {
            $lower[strtolower((string) $name)] = implode(', ', $values);
        }
        return new HttpResponse($response->getStatusCode(), $lower, (string) $response->getBody());
    }
}
```

`Model/Engine/Connection.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

final class Connection
{
    public readonly string $engineUrl;

    public function __construct(string $engineUrl, public readonly string $apiKey)
    {
        $this->engineUrl = rtrim($engineUrl, '/');
    }
}
```

`Model/Engine/EngineResult.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

final class EngineResult
{
    /**
     * @param array<mixed>|null $json decoded body when it is a JSON object/array
     */
    public function __construct(
        public readonly ?int $status,
        public readonly ?array $json,
        public readonly ?string $account,
        public readonly ?int $retryAfter,
        public readonly ?string $transportError
    ) {
    }

    public static function fromResponse(HttpResponse $r): self
    {
        $json = null;
        if ($r->body !== '') {
            try {
                $decoded = json_decode($r->body, true, 64, JSON_THROW_ON_ERROR);
                $json = is_array($decoded) ? $decoded : null;
            } catch (\JsonException) {
                $json = null;
            }
        }
        $retry = $r->header('retry-after');
        return new self(
            $r->status,
            $json,
            $r->header('x-answereng-account'),
            ($retry !== null && ctype_digit(trim($retry))) ? (int) trim($retry) : null,
            null
        );
    }

    public static function transportFailure(string $message): self
    {
        return new self(null, null, null, null, $message);
    }

    public function isSuccess(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    public function code(): ?string
    {
        $code = $this->json['code'] ?? null;
        return is_string($code) ? $code : null;
    }

    public function limit(): ?int
    {
        $limit = $this->json['limit'] ?? null;
        return is_int($limit) ? $limit : null;
    }

    public function purgeAfter(): ?string
    {
        $value = $this->json['purge_after'] ?? null;
        return is_string($value) ? $value : null;
    }
}
```

`Model/Engine/ErrorCode.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

/** Mirrors the engine's tests/fixtures/error_codes.json (ErrorCodeTest pins it). */
enum ErrorCode: string
{
    case InvalidKey = 'invalid_key';
    case StoreLimit = 'store_limit';
    case ProductLimit = 'product_limit';
    case StoreOwnedElsewhere = 'store_owned_elsewhere';
    case ValidationFailed = 'validation_failed';
    case UnknownStore = 'unknown_store';
    case RunInProgress = 'run_in_progress';
    case StorePendingDeletion = 'store_pending_deletion';
    case ConfirmationMismatch = 'confirmation_mismatch';
    case PurgeInProgress = 'purge_in_progress';
    case InvalidRequest = 'invalid_request';
    case Throttled = 'throttled';
    case QuotaExceeded = 'quota_exceeded';
}
```

`Model/Engine/EngineClient.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

use Psr\Log\LoggerInterface;

/**
 * The engine's connector API. Writes are sent once; GETs retry once on a
 * 5xx or transport failure (the engine's database auto-pauses). Logs carry
 * method, path and status only — never the key or a body.
 */
final class EngineClient
{
    private const WRITE_TIMEOUT = 30;
    private const READ_TIMEOUT = 15;

    public function __construct(
        private readonly Transport $transport,
        private readonly LoggerInterface $logger
    ) {
    }

    public function postCatalog(Connection $c, string $json): EngineResult
    {
        return $this->once('POST', $c, '/catalog', $json, self::WRITE_TIMEOUT);
    }

    public function clearCatalog(Connection $c, string $storeKey): EngineResult
    {
        return $this->once('DELETE', $c, '/stores/' . rawurlencode($storeKey) . '/catalog', null, self::WRITE_TIMEOUT);
    }

    public function deleteStore(Connection $c, string $storeKey): EngineResult
    {
        $body = json_encode(['confirm_store_key' => $storeKey], JSON_THROW_ON_ERROR);
        return $this->once('DELETE', $c, '/stores/' . rawurlencode($storeKey), $body, self::WRITE_TIMEOUT);
    }

    public function restoreStore(Connection $c, string $storeKey): EngineResult
    {
        return $this->once('POST', $c, '/stores/' . rawurlencode($storeKey) . '/restore', null, self::WRITE_TIMEOUT);
    }

    public function listStores(Connection $c): EngineResult
    {
        return $this->read($c, '/stores');
    }

    public function summary(Connection $c, string $storeKey): EngineResult
    {
        return $this->read($c, '/stores/' . rawurlencode($storeKey) . '/summary');
    }

    private function read(Connection $c, string $path): EngineResult
    {
        $first = $this->once('GET', $c, $path, null, self::READ_TIMEOUT);
        if ($first->status !== null && $first->status < 500) {
            return $first;
        }
        return $this->once('GET', $c, $path, null, self::READ_TIMEOUT);
    }

    private function once(string $method, Connection $c, string $path, ?string $body, int $timeout): EngineResult
    {
        $headers = ['x-api-key' => $c->apiKey, 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        try {
            $result = EngineResult::fromResponse(
                $this->transport->send($method, $c->engineUrl . $path, $headers, $body, $timeout)
            );
        } catch (TransportException $e) {
            $this->logger->warning('AnswerEng request failed', ['method' => $method, 'path' => $path,
                'error' => mb_substr($e->getMessage(), 0, 200)]);
            return EngineResult::transportFailure(mb_substr($e->getMessage(), 0, 200));
        }
        $this->logger->info('AnswerEng request', ['method' => $method, 'path' => $path,
            'status' => $result->status, 'code' => $result->code()]);
        return $result;
    }
}
```

`etc/di.xml` (later tasks add to it):

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <preference for="Fullspec\AnswerEng\Model\Engine\Transport"
                type="Fullspec\AnswerEng\Model\Engine\GuzzleTransport"/>
    <type name="Fullspec\AnswerEng\Model\Engine\GuzzleTransport">
        <arguments>
            <argument name="client" xsi:type="object">GuzzleHttp\Client</argument>
        </arguments>
    </type>

    <virtualType name="Fullspec\AnswerEng\Logger\Handler" type="Magento\Framework\Logger\Handler\Base">
        <arguments>
            <argument name="fileName" xsi:type="string">/var/log/answereng.log</argument>
        </arguments>
    </virtualType>
    <virtualType name="Fullspec\AnswerEng\Logger" type="Magento\Framework\Logger\Monolog">
        <arguments>
            <argument name="name" xsi:type="string">answereng</argument>
            <argument name="handlers" xsi:type="array">
                <item name="file" xsi:type="object">Fullspec\AnswerEng\Logger\Handler</item>
            </argument>
        </arguments>
    </virtualType>
    <type name="Fullspec\AnswerEng\Model\Engine\EngineClient">
        <arguments>
            <argument name="logger" xsi:type="object">Fullspec\AnswerEng\Logger</argument>
        </arguments>
    </type>
</config>
```

- [ ] **Step 4: Run the tests, lint and analyse**

Run: `vendor/bin/phpunit --testsuite Unit` then `vendor/bin/phpcs` and `vendor/bin/phpstan analyse --no-progress`
Expected: all PASS / no errors.

- [ ] **Step 5: Commit**

```bash
git add Model/Engine etc/di.xml Test/Unit/Engine
git commit -m "feat: engine HTTP client with codes, account header and GET retry" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Response classification and sanitized error detail

**Files:**
- Create: `Model/Sync/OutcomeKind.php`, `Model/Sync/Outcome.php`, `Model/Sync/ErrorDetail.php`, `Model/Sync/Classifier.php`
- Test: `Test/Unit/Sync/ClassifierTest.php`, `Test/Unit/Sync/ErrorDetailTest.php`

**Interfaces:**
- Consumes: `EngineResult` (Task 2).
- Produces: enum `OutcomeKind` (`Success`, `AlreadyClear`, `Transient`, `Throttled`, `Paused`); `Outcome(OutcomeKind $kind, ?string $reason, string $detail, ?int $retryAfterSeconds = null, ?string $purgeAfter = null, ?int $limit = null)`; `Classifier::classify(EngineResult $r, bool $isClear = false): Outcome`; `Classifier::CATALOG_DEPENDENT`; `ErrorDetail::of(EngineResult $r): string`.

- [ ] **Step 1: Write the failing tests**

`Test/Unit/Sync/ClassifierTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Sync;

use Fullspec\AnswerEng\Model\Engine\EngineResult;
use Fullspec\AnswerEng\Model\Engine\HttpResponse;
use Fullspec\AnswerEng\Model\Sync\Classifier;
use Fullspec\AnswerEng\Model\Sync\OutcomeKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClassifierTest extends TestCase
{
    private function res(int $status, string $body = '', array $headers = []): EngineResult
    {
        return EngineResult::fromResponse(new HttpResponse($status, $headers, $body));
    }

    /** @return array<string,array{0:EngineResult,1:OutcomeKind,2:?string}> */
    public static function cases(): array
    {
        $r = fn (int $s, string $b = '', array $h = []) =>
            EngineResult::fromResponse(new HttpResponse($s, $h, $b));
        return [
            'success' => [$r(200, '{"store_id":1}'), OutcomeKind::Success, null],
            'transport' => [EngineResult::transportFailure('timeout'), OutcomeKind::Transient, null],
            'html 502' => [$r(502, '<html>Bad Gateway</html>'), OutcomeKind::Transient, null],
            'run in progress' => [$r(409, '{"code":"run_in_progress"}'), OutcomeKind::Transient, 'run_in_progress'],
            'invalid key' => [$r(403, '{"code":"invalid_key"}'), OutcomeKind::Paused, 'invalid_key'],
            'gateway forbidden' => [$r(403, '{"error":"Forbidden","code":"invalid_key"}'), OutcomeKind::Paused, 'invalid_key'],
            'store limit' => [$r(403, '{"code":"store_limit","limit":1}'), OutcomeKind::Paused, 'store_limit'],
            'product limit' => [$r(403, '{"code":"product_limit","limit":5000}'), OutcomeKind::Paused, 'product_limit'],
            'owned elsewhere' => [$r(403, '{"code":"store_owned_elsewhere"}'), OutcomeKind::Paused, 'store_owned_elsewhere'],
            'pending deletion' => [$r(409, '{"code":"store_pending_deletion","purge_after":"2026-10-14T06:00:00+00:00"}'),
                OutcomeKind::Paused, 'store_pending_deletion'],
            'validation' => [$r(422, '{"code":"validation_failed","errors":[]}'), OutcomeKind::Paused, 'validation_failed'],
            'missing code' => [$r(403, '{"error":"nope"}'), OutcomeKind::Paused, 'unexpected'],
            'unknown 4xx' => [$r(418, '{}'), OutcomeKind::Paused, 'unexpected'],
        ];
    }

    #[DataProvider('cases')]
    public function testClassification(EngineResult $result, OutcomeKind $kind, ?string $reason): void
    {
        $o = (new Classifier())->classify($result);
        self::assertSame($kind, $o->kind);
        self::assertSame($reason, $o->reason);
    }

    public function testThrottleWaitsAtLeastFiveMinutes(): void
    {
        $c = new Classifier();
        $short = $c->classify($this->res(429, '{"code":"throttled"}', ['retry-after' => '30']));
        $long = $c->classify($this->res(429, '{"code":"throttled"}', ['retry-after' => '900']));
        $none = $c->classify($this->res(429, '{"code":"quota_exceeded"}'));
        self::assertSame(OutcomeKind::Throttled, $short->kind);
        self::assertSame([300, 900, 300], [$short->retryAfterSeconds, $long->retryAfterSeconds, $none->retryAfterSeconds]);
    }

    public function testUnknownStoreOnClearMeansAlreadyClear(): void
    {
        $c = new Classifier();
        self::assertSame(OutcomeKind::AlreadyClear,
            $c->classify($this->res(404, '{"code":"unknown_store"}'), true)->kind);
        self::assertSame(OutcomeKind::Paused,
            $c->classify($this->res(404, '{"code":"unknown_store"}'))->kind);
    }

    public function testLimitAndPurgeAfterAreCarried(): void
    {
        $c = new Classifier();
        self::assertSame(1, $c->classify($this->res(403, '{"code":"store_limit","limit":1}'))->limit);
        self::assertSame('2026-10-14T06:00:00+00:00',
            $c->classify($this->res(409, '{"code":"store_pending_deletion","purge_after":"2026-10-14T06:00:00+00:00"}'))->purgeAfter);
    }
}
```

`Test/Unit/Sync/ErrorDetailTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Sync;

use Fullspec\AnswerEng\Model\Engine\EngineResult;
use Fullspec\AnswerEng\Model\Engine\HttpResponse;
use Fullspec\AnswerEng\Model\Sync\ErrorDetail;
use PHPUnit\Framework\TestCase;

final class ErrorDetailTest extends TestCase
{
    public function testValidationDetailKeepsOnlyLocAndTypeAndNeverInput(): void
    {
        $errors = [];
        for ($i = 0; $i < 8; $i++) {
            $errors[] = ['type' => 'string_type', 'loc' => ['products', $i, 'title'],
                'msg' => 'secret product text', 'input' => ['title' => 'SECRET-INPUT']];
        }
        $detail = ErrorDetail::of(EngineResult::fromResponse(new HttpResponse(422, [],
            json_encode(['code' => 'validation_failed', 'error' => 'invalid catalog', 'errors' => $errors]))));
        self::assertStringStartsWith('HTTP 422 validation_failed', $detail);
        self::assertStringContainsString('products.0.title:string_type', $detail);
        self::assertStringNotContainsString('SECRET', $detail);
        self::assertStringNotContainsString('secret', $detail);
        self::assertSame(5, substr_count($detail, ':string_type'));
        self::assertLessThanOrEqual(1000, mb_strlen($detail));
    }

    public function testCodeAndLimitOnlyNeverErrorText(): void
    {
        $detail = ErrorDetail::of(EngineResult::fromResponse(new HttpResponse(403, [],
            '{"error":"plan allows 1 store(s)","code":"store_limit","limit":1}')));
        self::assertSame('HTTP 403 store_limit limit=1', $detail);
    }

    public function testTransportDetail(): void
    {
        self::assertSame('transport: timed out', ErrorDetail::of(EngineResult::transportFailure('timed out')));
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --testsuite Unit --filter 'Classifier|ErrorDetail'`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the classifier**

`Model/Sync/OutcomeKind.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

enum OutcomeKind: string
{
    case Success = 'success';
    case AlreadyClear = 'already_clear';
    case Transient = 'transient';
    case Throttled = 'throttled';
    case Paused = 'paused';
}
```

`Model/Sync/Outcome.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

final class Outcome
{
    public function __construct(
        public readonly OutcomeKind $kind,
        public readonly ?string $reason,
        public readonly string $detail,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $purgeAfter = null,
        public readonly ?int $limit = null
    ) {
    }
}
```

`Model/Sync/ErrorDetail.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Engine\EngineResult;

/**
 * The only engine-error text the module ever stores or shows: status, code,
 * limit, and for 422 at most five loc/type pairs. Never `error`, `msg` or
 * `input` (validation bodies echo submitted catalog data).
 */
final class ErrorDetail
{
    private const MAX_ITEMS = 5;
    private const MAX_ITEM = 200;
    private const MAX_TOTAL = 1000;

    public static function of(EngineResult $r): string
    {
        if ($r->status === null) {
            return mb_substr('transport: ' . ($r->transportError ?? 'error'), 0, self::MAX_TOTAL);
        }
        $parts = ['HTTP ' . $r->status];
        if ($r->code() !== null) {
            $parts[] = $r->code();
        }
        if ($r->limit() !== null) {
            $parts[] = 'limit=' . $r->limit();
        }
        $detail = implode(' ', $parts);
        $errors = $r->json['errors'] ?? null;
        if ($r->status === 422 && is_array($errors)) {
            $items = [];
            foreach (array_slice($errors, 0, self::MAX_ITEMS) as $error) {
                if (!is_array($error)) {
                    continue;
                }
                $loc = is_array($error['loc'] ?? null)
                    ? implode('.', array_map(static fn ($p) => is_scalar($p) ? (string) $p : '?', $error['loc']))
                    : '?';
                $type = is_string($error['type'] ?? null) ? $error['type'] : '?';
                $items[] = mb_substr($loc . ':' . $type, 0, self::MAX_ITEM);
            }
            if ($items !== []) {
                $detail .= '; ' . implode(', ', $items);
            }
        }
        return mb_substr($detail, 0, self::MAX_TOTAL);
    }
}
```

`Model/Sync/Classifier.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Engine\EngineResult;

/** Spec §6 "Response classification": branches on status and `code` only. */
final class Classifier
{
    public const MIN_THROTTLE_SECONDS = 300;
    public const CATALOG_DEPENDENT = ['validation_failed', 'product_limit'];
    private const PAUSING_CODES = ['invalid_key', 'store_limit', 'product_limit', 'store_owned_elsewhere',
        'store_pending_deletion', 'validation_failed'];

    public function classify(EngineResult $r, bool $isClear = false): Outcome
    {
        $detail = ErrorDetail::of($r);
        $code = $r->code();
        if ($r->status === null || $r->status >= 500) {
            return new Outcome(OutcomeKind::Transient, null, $detail);
        }
        if ($r->isSuccess()) {
            return new Outcome(OutcomeKind::Success, null, 'HTTP ' . $r->status);
        }
        if ($r->status === 429) {
            return new Outcome(OutcomeKind::Throttled, $code ?? 'throttled', $detail,
                max(self::MIN_THROTTLE_SECONDS, $r->retryAfter ?? 0));
        }
        if ($r->status === 409 && $code === 'run_in_progress') {
            return new Outcome(OutcomeKind::Transient, 'run_in_progress', $detail);
        }
        if ($isClear && $r->status === 404 && $code === 'unknown_store') {
            return new Outcome(OutcomeKind::AlreadyClear, null, $detail);
        }
        if ($code !== null && in_array($code, self::PAUSING_CODES, true)) {
            return new Outcome(OutcomeKind::Paused, $code, $detail, null, $r->purgeAfter(), $r->limit());
        }
        return new Outcome(OutcomeKind::Paused, 'unexpected', $detail);
    }
}
```

- [ ] **Step 4: Run the tests, lint and analyse**

Run: `vendor/bin/phpunit --testsuite Unit` then `vendor/bin/phpcs` and `vendor/bin/phpstan analyse --no-progress`
Expected: all PASS / no errors.

- [ ] **Step 5: Commit**

```bash
git add Model/Sync/OutcomeKind.php Model/Sync/Outcome.php Model/Sync/ErrorDetail.php Model/Sync/Classifier.php Test/Unit/Sync
git commit -m "feat: classify engine responses by code with sanitized detail" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Database port, schema and MariaDB test harness

**Files:**
- Create: `Model/Db/SqlExecutor.php`, `Model/Db/MagentoSqlExecutor.php`
- Create: `etc/db_schema.xml`, `etc/db_schema_whitelist.json`
- Create: `Test/Db/PdoSqlExecutor.php`, `Test/Db/SchemaLoader.php`, `Test/Db/DbTestCase.php`, `Test/Db/SchemaTest.php`
- Modify: `etc/di.xml` (preference)

**Interfaces:**
- Produces: `SqlExecutor` with `table(string $name): string`, `execute(string $sql, array $bind = []): int` (affected rows), `fetchAll(string $sql, array $bind = []): array`, `fetchRow(string $sql, array $bind = []): ?array`, `fetchOne(string $sql, array $bind = []): mixed`, `lastInsertId(): int`; tables `answereng_sync_state`, `answereng_sync_log`, `answereng_remote_store`, `answereng_remote_store_view`; `DbTestCase` providing `$this->db` (a fresh schema per test).

- [ ] **Step 1: Write the schema**

`etc/db_schema.xml`:

```xml
<?xml version="1.0"?>
<schema xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Setup/Declaration/Schema/etc/schema.xsd">
    <table name="answereng_sync_state" resource="default" engine="innodb" comment="AnswerEng per-store-view sync state">
        <column xsi:type="smallint" name="store_id" unsigned="true" nullable="false" comment="Store view ID"/>
        <column xsi:type="smallint" name="dirty" unsigned="true" nullable="false" default="0" comment="Catalog changed since the last push"/>
        <column xsi:type="datetime" name="last_change_at" nullable="true" comment="Last catalog change (UTC)"/>
        <column xsi:type="varchar" name="state" length="16" nullable="false" default="ok" comment="ok|retrying|exhausted|blocked|paused"/>
        <column xsi:type="varchar" name="reason" length="64" nullable="true" comment="Blocked or paused reason code"/>
        <column xsi:type="varchar" name="retry_reason" length="16" nullable="true" comment="throttled while honoring Retry-After"/>
        <column xsi:type="smallint" name="attempts" unsigned="true" nullable="false" default="0" comment="Consecutive transient failures"/>
        <column xsi:type="datetime" name="next_attempt_at" nullable="true" comment="Earliest retry (UTC)"/>
        <column xsi:type="int" name="config_revision" unsigned="true" nullable="false" default="1" comment="Bumped by configuration changes"/>
        <column xsi:type="varchar" name="settings_hash" length="64" nullable="true" comment="Hash of the effective settings"/>
        <column xsi:type="datetime" name="last_sync_at" nullable="true" comment="Last successful push or clear (UTC)"/>
        <column xsi:type="int" name="product_count" unsigned="true" nullable="true" comment="Products in the last push"/>
        <column xsi:type="varchar" name="synced_store_key" length="255" nullable="true" comment="Store key of the last successful push"/>
        <column xsi:type="varchar" name="account_id" length="64" nullable="true" comment="Account last seen for account_key_hash"/>
        <column xsi:type="varchar" name="account_key_hash" length="64" nullable="true" comment="sha256 of the API key that account was seen with"/>
        <column xsi:type="text" name="snapshot_json" nullable="true" comment="Last summary response"/>
        <column xsi:type="varchar" name="snapshot_fingerprint" length="64" nullable="true" comment="Connection fingerprint of the snapshot"/>
        <column xsi:type="datetime" name="snapshot_fetched_at" nullable="true" comment="When the snapshot was fetched (UTC)"/>
        <constraint xsi:type="primary" referenceId="PRIMARY">
            <column name="store_id"/>
        </constraint>
        <constraint xsi:type="foreign" referenceId="ANSWERENG_SYNC_STATE_STORE_ID_STORE_STORE_ID"
                    table="answereng_sync_state" column="store_id" referenceTable="store"
                    referenceColumn="store_id" onDelete="CASCADE"/>
    </table>
    <table name="answereng_sync_log" resource="default" engine="innodb" comment="AnswerEng sync log (last 20 per view)">
        <column xsi:type="int" name="log_id" unsigned="true" nullable="false" identity="true" comment="Log ID"/>
        <column xsi:type="smallint" name="store_id" unsigned="true" nullable="false" comment="Store view ID"/>
        <column xsi:type="datetime" name="created_at" nullable="false" comment="When (UTC)"/>
        <column xsi:type="varchar" name="source" length="32" nullable="false" comment="debounce|reconcile|manual|cli|admin"/>
        <column xsi:type="varchar" name="outcome" length="32" nullable="false" comment="Outcome label"/>
        <column xsi:type="varchar" name="detail" length="1000" nullable="true" comment="Sanitized detail"/>
        <column xsi:type="varchar" name="admin_user" length="64" nullable="true" comment="Admin username for admin actions"/>
        <constraint xsi:type="primary" referenceId="PRIMARY">
            <column name="log_id"/>
        </constraint>
        <index referenceId="ANSWERENG_SYNC_LOG_STORE_ID" indexType="btree">
            <column name="store_id"/>
        </index>
        <constraint xsi:type="foreign" referenceId="ANSWERENG_SYNC_LOG_STORE_ID_STORE_STORE_ID"
                    table="answereng_sync_log" column="store_id" referenceTable="store"
                    referenceColumn="store_id" onDelete="CASCADE"/>
    </table>
    <table name="answereng_remote_store" resource="default" engine="innodb" comment="AnswerEng remote store identities">
        <column xsi:type="int" name="remote_store_id" unsigned="true" nullable="false" identity="true" comment="Identity ID"/>
        <column xsi:type="varchar" name="engine_url" length="255" nullable="false" comment="Engine URL used"/>
        <column xsi:type="varchar" name="account_id" length="64" nullable="false" comment="X-AnswerEng-Account"/>
        <column xsi:type="varchar" name="store_key" length="255" nullable="false" comment="Remote store key"/>
        <column xsi:type="varchar" name="remote_status" length="20" nullable="false" default="active" comment="active|pending_deletion|deleted"/>
        <column xsi:type="datetime" name="purge_after" nullable="true" comment="Purge date while pending (UTC)"/>
        <column xsi:type="datetime" name="first_synced_at" nullable="false" comment="First successful push (UTC)"/>
        <constraint xsi:type="primary" referenceId="PRIMARY">
            <column name="remote_store_id"/>
        </constraint>
        <constraint xsi:type="unique" referenceId="ANSWERENG_REMOTE_STORE_IDENTITY">
            <column name="engine_url"/>
            <column name="account_id"/>
            <column name="store_key"/>
        </constraint>
    </table>
    <table name="answereng_remote_store_view" resource="default" engine="innodb" comment="Store views that pushed to a remote store">
        <column xsi:type="int" name="remote_store_id" unsigned="true" nullable="false" comment="Identity ID"/>
        <column xsi:type="smallint" name="store_id" unsigned="true" nullable="false" comment="Store view ID"/>
        <column xsi:type="datetime" name="last_synced_at" nullable="false" comment="Last push from this view (UTC)"/>
        <constraint xsi:type="primary" referenceId="PRIMARY">
            <column name="remote_store_id"/>
            <column name="store_id"/>
        </constraint>
        <constraint xsi:type="foreign" referenceId="ANSWERENG_REMOTE_STORE_VIEW_REMOTE_STORE_ID"
                    table="answereng_remote_store_view" column="remote_store_id"
                    referenceTable="answereng_remote_store" referenceColumn="remote_store_id" onDelete="CASCADE"/>
        <constraint xsi:type="foreign" referenceId="ANSWERENG_REMOTE_STORE_VIEW_STORE_ID_STORE_STORE_ID"
                    table="answereng_remote_store_view" column="store_id" referenceTable="store"
                    referenceColumn="store_id" onDelete="CASCADE"/>
    </table>
</schema>
```

`etc/db_schema_whitelist.json`:

```json
{
    "answereng_sync_state": {
        "column": {"store_id": true, "dirty": true, "last_change_at": true, "state": true, "reason": true,
            "retry_reason": true, "attempts": true, "next_attempt_at": true, "config_revision": true,
            "settings_hash": true, "last_sync_at": true, "product_count": true, "synced_store_key": true,
            "account_id": true, "account_key_hash": true, "snapshot_json": true,
            "snapshot_fingerprint": true, "snapshot_fetched_at": true},
        "constraint": {"PRIMARY": true, "ANSWERENG_SYNC_STATE_STORE_ID_STORE_STORE_ID": true}
    },
    "answereng_sync_log": {
        "column": {"log_id": true, "store_id": true, "created_at": true, "source": true, "outcome": true,
            "detail": true, "admin_user": true},
        "index": {"ANSWERENG_SYNC_LOG_STORE_ID": true},
        "constraint": {"PRIMARY": true, "ANSWERENG_SYNC_LOG_STORE_ID_STORE_STORE_ID": true}
    },
    "answereng_remote_store": {
        "column": {"remote_store_id": true, "engine_url": true, "account_id": true, "store_key": true,
            "remote_status": true, "purge_after": true, "first_synced_at": true},
        "constraint": {"PRIMARY": true, "ANSWERENG_REMOTE_STORE_IDENTITY": true}
    },
    "answereng_remote_store_view": {
        "column": {"remote_store_id": true, "store_id": true, "last_synced_at": true},
        "constraint": {"PRIMARY": true, "ANSWERENG_REMOTE_STORE_VIEW_REMOTE_STORE_ID": true,
            "ANSWERENG_REMOTE_STORE_VIEW_STORE_ID_STORE_STORE_ID": true}
    }
}
```

- [ ] **Step 2: Write the port and the Magento adapter**

`Model/Db/SqlExecutor.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Db;

/**
 * The repositories' only view of the database: plain SQL with positional
 * `?` placeholders. Magento runs it through its connection; tests run it
 * through PDO against MariaDB, so the SQL itself is what gets tested.
 */
interface SqlExecutor
{
    /** Physical name for a logical table (Magento may add a prefix). */
    public function table(string $name): string;

    /** @param list<scalar|null> $bind @return int rows changed (MySQL counts changed, not matched) */
    public function execute(string $sql, array $bind = []): int;

    /** @param list<scalar|null> $bind @return list<array<string,mixed>> */
    public function fetchAll(string $sql, array $bind = []): array;

    /** @param list<scalar|null> $bind @return array<string,mixed>|null */
    public function fetchRow(string $sql, array $bind = []): ?array;

    /** @param list<scalar|null> $bind */
    public function fetchOne(string $sql, array $bind = []): mixed;

    public function lastInsertId(): int;
}
```

`Model/Db/MagentoSqlExecutor.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Db;

use Magento\Framework\App\ResourceConnection;

final class MagentoSqlExecutor implements SqlExecutor
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    public function execute(string $sql, array $bind = []): int
    {
        return $this->resource->getConnection()->query($sql, $bind)->rowCount();
    }

    public function fetchAll(string $sql, array $bind = []): array
    {
        return $this->resource->getConnection()->fetchAll($sql, $bind);
    }

    public function fetchRow(string $sql, array $bind = []): ?array
    {
        $row = $this->resource->getConnection()->fetchRow($sql, $bind);
        return $row === false ? null : $row;
    }

    public function fetchOne(string $sql, array $bind = []): mixed
    {
        $value = $this->resource->getConnection()->fetchOne($sql, $bind);
        return $value === false ? null : $value;
    }

    public function lastInsertId(): int
    {
        return (int) $this->resource->getConnection()->lastInsertId();
    }
}
```

Add to `etc/di.xml` inside `<config>`:

```xml
    <preference for="Fullspec\AnswerEng\Model\Db\SqlExecutor"
                type="Fullspec\AnswerEng\Model\Db\MagentoSqlExecutor"/>
```

- [ ] **Step 3: Write the test harness**

`Test/Db/PdoSqlExecutor.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Db\SqlExecutor;

final class PdoSqlExecutor implements SqlExecutor
{
    public function __construct(public readonly \PDO $pdo)
    {
    }

    public function table(string $name): string
    {
        return $name;
    }

    public function execute(string $sql, array $bind = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        return $stmt->rowCount();
    }

    public function fetchAll(string $sql, array $bind = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function fetchRow(string $sql, array $bind = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function fetchOne(string $sql, array $bind = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }
}
```

`Test/Db/SchemaLoader.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

/**
 * Builds MySQL DDL from etc/db_schema.xml so repository tests run against
 * exactly the declared schema. Supports the column types this module uses.
 * Foreign keys to tables outside the file (Magento's `store`) are skipped.
 */
final class SchemaLoader
{
    /** @return list<string> table names in declaration order */
    public static function tables(): array
    {
        $names = [];
        foreach (self::xml()->table as $table) {
            $names[] = (string) $table['name'];
        }
        return $names;
    }

    /** @return list<string> CREATE TABLE statements in declaration order */
    public static function ddl(): array
    {
        $xml = self::xml();
        $declared = self::tables();
        $statements = [];
        foreach ($xml->table as $table) {
            $lines = [];
            foreach ($table->column as $col) {
                $lines[] = self::column($col);
            }
            foreach ($table->constraint as $c) {
                $type = (string) $c->attributes('xsi', true)->type;
                $cols = [];
                foreach ($c->column as $cc) {
                    $cols[] = '`' . $cc['name'] . '`';
                }
                if ($type === 'primary') {
                    $lines[] = 'PRIMARY KEY (' . implode(', ', $cols) . ')';
                } elseif ($type === 'unique') {
                    $lines[] = 'UNIQUE KEY `' . $c['referenceId'] . '` (' . implode(', ', $cols) . ')';
                } elseif ($type === 'foreign' && in_array((string) $c['referenceTable'], $declared, true)) {
                    $lines[] = sprintf('CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s',
                        $c['referenceId'], $c['column'], $c['referenceTable'], $c['referenceColumn'],
                        (string) ($c['onDelete'] ?: 'NO ACTION'));
                }
            }
            foreach ($table->index as $index) {
                $cols = [];
                foreach ($index->column as $ic) {
                    $cols[] = '`' . $ic['name'] . '`';
                }
                $lines[] = 'KEY `' . $index['referenceId'] . '` (' . implode(', ', $cols) . ')';
            }
            $statements[] = 'CREATE TABLE `' . $table['name'] . "` (\n  " . implode(",\n  ", $lines)
                . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        }
        return $statements;
    }

    private static function column(\SimpleXMLElement $col): string
    {
        $type = (string) $col->attributes('xsi', true)->type;
        $sql = match ($type) {
            'smallint' => 'SMALLINT',
            'int' => 'INT',
            'varchar' => 'VARCHAR(' . (int) $col['length'] . ')',
            'text' => 'TEXT',
            'datetime' => 'DATETIME',
            default => throw new \RuntimeException("unsupported column type $type"),
        };
        if ((string) $col['unsigned'] === 'true') {
            $sql .= ' UNSIGNED';
        }
        $sql .= (string) $col['nullable'] === 'false' ? ' NOT NULL' : ' NULL';
        if (isset($col['default'])) {
            $default = (string) $col['default'];
            $sql .= ' DEFAULT ' . ($type === 'varchar' ? "'" . addslashes($default) . "'" : $default);
        }
        if ((string) $col['identity'] === 'true') {
            $sql .= ' AUTO_INCREMENT';
        }
        return '`' . $col['name'] . '` ' . $sql;
    }

    private static function xml(): \SimpleXMLElement
    {
        return simplexml_load_file(dirname(__DIR__, 2) . '/etc/db_schema.xml');
    }
}
```

`Test/Db/DbTestCase.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use PHPUnit\Framework\TestCase;

/** Fresh module tables per test in the database named by ANSWERENG_TEST_DB_DSN. */
abstract class DbTestCase extends TestCase
{
    protected PdoSqlExecutor $db;

    protected function setUp(): void
    {
        $dsn = getenv('ANSWERENG_TEST_DB_DSN');
        if (!$dsn) {
            self::markTestSkipped('ANSWERENG_TEST_DB_DSN not set');
        }
        $pdo = new \PDO($dsn, (string) getenv('ANSWERENG_TEST_DB_USER'), (string) getenv('ANSWERENG_TEST_DB_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(SchemaLoader::tables()) as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        foreach (SchemaLoader::ddl() as $ddl) {
            $pdo->exec($ddl);
        }
        $this->db = new PdoSqlExecutor($pdo);
    }
}
```

`Test/Db/SchemaTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

final class SchemaTest extends DbTestCase
{
    public function testDefaults(): void
    {
        $this->db->execute('INSERT INTO answereng_sync_state (store_id) VALUES (?)', [1]);
        $row = $this->db->fetchRow('SELECT * FROM answereng_sync_state WHERE store_id = 1');
        self::assertSame(['0', 'ok', '0', '1'],
            [$row['dirty'], $row['state'], $row['attempts'], $row['config_revision']]);
    }

    public function testRemoteIdentityIsUnique(): void
    {
        $sql = 'INSERT INTO answereng_remote_store (engine_url, account_id, store_key, first_synced_at) VALUES (?, ?, ?, ?)';
        $this->db->execute($sql, ['https://e', 'acct_1', 's', '2026-10-07 00:00:00']);
        $this->expectException(\PDOException::class);
        $this->db->execute($sql, ['https://e', 'acct_1', 's', '2026-10-07 00:00:00']);
    }

    public function testViewLinksCascadeWithTheIdentity(): void
    {
        $this->db->execute('INSERT INTO answereng_remote_store (engine_url, account_id, store_key, first_synced_at) VALUES (?, ?, ?, ?)',
            ['https://e', 'acct_1', 's', '2026-10-07 00:00:00']);
        $id = $this->db->lastInsertId();
        $this->db->execute('INSERT INTO answereng_remote_store_view (remote_store_id, store_id, last_synced_at) VALUES (?, ?, ?)',
            [$id, 1, '2026-10-07 00:00:00']);
        $this->db->execute('DELETE FROM answereng_remote_store WHERE remote_store_id = ?', [$id]);
        self::assertSame('0', (string) $this->db->fetchOne('SELECT COUNT(*) FROM answereng_remote_store_view'));
    }
}
```

- [ ] **Step 4: Run the Db suite, lint and analyse**

Run (with the env from Task 1 Step 8): `vendor/bin/phpunit --testsuite Db` then `vendor/bin/phpcs` and `vendor/bin/phpstan analyse --no-progress`
Expected: 3 tests PASS; no lint/analysis errors.

- [ ] **Step 5: Commit**

```bash
git add Model/Db etc/db_schema.xml etc/db_schema_whitelist.json etc/di.xml Test/Db
git commit -m "feat: declarative schema and SQL executor with MariaDB test harness" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Sync state machine (repository) and clock

**Files:**
- Create: `Model/Support/Clock.php`, `Model/Support/SystemClock.php`, `Model/Support/Ts.php`
- Create: `Model/Sync/SyncState.php`, `Model/Sync/SyncStateRepository.php`
- Create: `Test/Support/FixedClock.php`, `Test/Db/SyncStateRepositoryTest.php`
- Modify: `etc/di.xml` (Clock preference)

**Interfaces:**
- Consumes: `SqlExecutor` (Task 4).
- Produces: `Clock::now(): \DateTimeImmutable` (UTC); `Ts::fmt(\DateTimeImmutable): string`, `Ts::parse(?string): ?\DateTimeImmutable`; `SyncState` (readonly: `storeId, dirty, lastChangeAt, state, reason, retryReason, attempts, nextAttemptAt, configRevision, settingsHash, lastSyncAt, productCount, syncedStoreKey, accountId, accountKeyHash, snapshotJson, snapshotFingerprint, snapshotFetchedAt`); `SyncStateRepository` constants `OK, RETRYING, EXHAUSTED, BLOCKED, PAUSED, DEBOUNCE_SECONDS = 300, MAX_TRANSIENT_ATTEMPTS = 3` and methods:
  - `ensure(int $storeId): void`
  - `get(int $storeId): SyncState` (ensures the row)
  - `markDirty(array $storeIds, \DateTimeImmutable $now): void`
  - `applySettingsHash(int $storeId, string $hash, \DateTimeImmutable $now): bool`
  - `selectForDebounce(array $enabledStoreIds, \DateTimeImmutable $now): array`
  - `selectForReconcile(array $enabledStoreIds, \DateTimeImmutable $now): array`
  - `recordSuccess(int $storeId, int $revision, \DateTimeImmutable $startedAt, \DateTimeImmutable $now, int $productCount, ?string $storeKey, ?string $reason): bool`
  - `recordTransient(int $storeId, int $revision, \DateTimeImmutable $now, ?int $throttleSeconds): bool`
  - `recordPaused(int $storeId, int $revision, string $reason): bool`
  - `recordBlocked(int $storeId, int $revision, string $reason): bool`
  - `recordAccount(int $storeId, string $accountId, string $apiKeyHash): void`
  - `throttledUntil(int $storeId, \DateTimeImmutable $now): ?\DateTimeImmutable`
  - `saveSnapshot(int $storeId, string $fingerprint, string $json, \DateTimeImmutable $now): void`
  - All `record*` return true when applied, false when the configuration revision moved on (superseded).

- [ ] **Step 1: Write the test clock and the failing repository tests**

`Test/Support/FixedClock.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Support;

use Fullspec\AnswerEng\Model\Support\Clock;

final class FixedClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $now = '2026-10-07 12:00:00')
    {
        $this->now = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+$seconds seconds");
    }
}
```

`Test/Db/SyncStateRepositoryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Sync\SyncStateRepository as Repo;
use Fullspec\AnswerEng\Test\Support\FixedClock;

final class SyncStateRepositoryTest extends DbTestCase
{
    private Repo $repo;
    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new Repo($this->db);
        $this->clock = new FixedClock();
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    private function set(int $storeId, array $fields): void
    {
        $this->repo->ensure($storeId);
        $sets = implode(', ', array_map(static fn ($k) => "`$k` = ?", array_keys($fields)));
        $this->db->execute("UPDATE answereng_sync_state SET $sets WHERE store_id = ?",
            [...array_values($fields), $storeId]);
    }

    public function testEnsureIsIdempotentAndGetReturnsDefaults(): void
    {
        $this->repo->ensure(1);
        $this->repo->ensure(1);
        $s = $this->repo->get(1);
        self::assertSame([Repo::OK, false, 0, 1], [$s->state, $s->dirty, $s->attempts, $s->configRevision]);
    }

    public function testMarkDirtyResetsExhaustedAndCatalogDependentPausesOnly(): void
    {
        $this->set(1, ['state' => 'exhausted', 'attempts' => 3]);
        $this->set(2, ['state' => 'paused', 'reason' => 'validation_failed']);
        $this->set(3, ['state' => 'paused', 'reason' => 'product_limit']);
        $this->set(4, ['state' => 'paused', 'reason' => 'invalid_key']);
        $this->set(5, ['state' => 'blocked', 'reason' => 'too_many_products']);
        $this->repo->markDirty([1, 2, 3, 4, 5, 6], $this->now());
        $states = [];
        foreach (range(1, 6) as $id) {
            $s = $this->repo->get($id);
            self::assertTrue($s->dirty);
            self::assertEquals($this->now(), $s->lastChangeAt);
            $states[$id] = [$s->state, $s->reason, $s->attempts];
        }
        self::assertSame([
            1 => ['ok', null, 0], 2 => ['ok', null, 0], 3 => ['ok', null, 0],
            4 => ['paused', 'invalid_key', 0], 5 => ['blocked', 'too_many_products', 0], 6 => ['ok', null, 0],
        ], $states);
    }

    public function testDebounceSelection(): void
    {
        $old = '2026-10-07 11:50:00';   // 10 min ago
        $recent = '2026-10-07 11:58:00'; // 2 min ago
        $this->set(1, ['dirty' => 1, 'last_change_at' => $old]);                                    // yes
        $this->set(2, ['dirty' => 1, 'last_change_at' => $recent]);                                 // too recent
        $this->set(3, ['dirty' => 1, 'last_change_at' => $old, 'state' => 'blocked']);              // yes
        $this->set(4, ['dirty' => 0, 'state' => 'retrying', 'next_attempt_at' => '2026-10-07 11:59:00']); // due, clean
        $this->set(5, ['dirty' => 1, 'last_change_at' => $old, 'state' => 'retrying',
            'next_attempt_at' => '2026-10-07 12:10:00']);                                          // not due
        $this->set(6, ['dirty' => 1, 'last_change_at' => $old, 'state' => 'exhausted']);            // never
        $this->set(7, ['dirty' => 1, 'last_change_at' => $old, 'state' => 'paused']);               // never
        $this->set(8, ['dirty' => 1, 'last_change_at' => $old]);                                    // disabled
        self::assertSame([1, 3, 4], $this->repo->selectForDebounce([1, 2, 3, 4, 5, 6, 7], $this->now()));
    }

    public function testReconcileSelection(): void
    {
        $this->set(1, []);
        $this->set(2, ['state' => 'blocked']);
        $this->set(3, ['state' => 'exhausted']);
        $this->set(4, ['state' => 'retrying', 'next_attempt_at' => '2026-10-07 11:59:00']);
        $this->set(5, ['state' => 'retrying', 'next_attempt_at' => '2026-10-07 12:10:00']);
        $this->set(6, ['state' => 'paused']);
        self::assertSame([1, 2, 3, 4, 9], $this->repo->selectForReconcile([1, 2, 3, 4, 5, 6, 9], $this->now()));
    }

    public function testSuccessClearsDirtyUnlessEditedDuringThePush(): void
    {
        $started = $this->now();
        $this->set(1, ['dirty' => 1, 'last_change_at' => '2026-10-07 11:00:00', 'state' => 'retrying', 'attempts' => 2]);
        $this->set(2, ['dirty' => 1, 'last_change_at' => '2026-10-07 12:00:30']); // edit during the push
        $this->clock->advance(60);
        $rev = $this->repo->get(1)->configRevision;
        self::assertTrue($this->repo->recordSuccess(1, $rev, $started, $this->now(), 42, 'shop.example.com', null));
        self::assertTrue($this->repo->recordSuccess(2, $rev, $started, $this->now(), 42, 'shop.example.com', null));
        $one = $this->repo->get(1);
        self::assertSame([false, 'ok', 0, 42, 'shop.example.com'],
            [$one->dirty, $one->state, $one->attempts, $one->productCount, $one->syncedStoreKey]);
        self::assertTrue($this->repo->get(2)->dirty);
    }

    public function testOutcomeIsDiscardedWhenTheRevisionMovedOn(): void
    {
        $this->repo->ensure(1);
        $rev = $this->repo->get(1)->configRevision;
        self::assertTrue($this->repo->applySettingsHash(1, 'new-hash', $this->now()));
        self::assertFalse($this->repo->recordSuccess(1, $rev, $this->now(), $this->now(), 1, 'k', null));
        self::assertFalse($this->repo->recordPaused(1, $rev, 'invalid_key'));
        self::assertSame('ok', $this->repo->get(1)->state);
    }

    public function testRepeatingAnIdenticalSuccessStillCountsAsApplied(): void
    {
        $this->repo->ensure(1);
        $rev = $this->repo->get(1)->configRevision;
        self::assertTrue($this->repo->recordSuccess(1, $rev, $this->now(), $this->now(), 3, 'k', null));
        self::assertTrue($this->repo->recordSuccess(1, $rev, $this->now(), $this->now(), 3, 'k', null));
    }

    public function testTransientRetriesAtFiveThenFifteenMinutesThenExhausts(): void
    {
        $this->repo->ensure(1);
        $rev = $this->repo->get(1)->configRevision;
        $this->repo->recordTransient(1, $rev, $this->now(), null);
        $s = $this->repo->get(1);
        self::assertSame(['retrying', 1, '2026-10-07 12:05:00'], [$s->state, $s->attempts, $s->nextAttemptAt?->format('Y-m-d H:i:s')]);
        $this->repo->recordTransient(1, $rev, $this->now(), null);
        $s = $this->repo->get(1);
        self::assertSame(['retrying', 2, '2026-10-07 12:15:00'], [$s->state, $s->attempts, $s->nextAttemptAt?->format('Y-m-d H:i:s')]);
        $this->repo->recordTransient(1, $rev, $this->now(), null);
        $s = $this->repo->get(1);
        self::assertSame(['exhausted', 3, null], [$s->state, $s->attempts, $s->nextAttemptAt]);
    }

    public function testThrottleHonoursRetryAfterAndIsReportedForManualSyncs(): void
    {
        $this->repo->ensure(1);
        $rev = $this->repo->get(1)->configRevision;
        $this->repo->recordTransient(1, $rev, $this->now(), 900);
        $s = $this->repo->get(1);
        self::assertSame(['retrying', 'throttled', '2026-10-07 12:15:00'],
            [$s->state, $s->retryReason, $s->nextAttemptAt?->format('Y-m-d H:i:s')]);
        self::assertSame('2026-10-07 12:15:00', $this->repo->throttledUntil(1, $this->now())?->format('Y-m-d H:i:s'));
        $this->clock->advance(901);
        self::assertNull($this->repo->throttledUntil(1, $this->now()));
    }

    public function testPausedAndBlockedResetRetryBookkeeping(): void
    {
        $this->set(1, ['state' => 'retrying', 'attempts' => 2, 'next_attempt_at' => '2026-10-07 12:15:00']);
        $rev = $this->repo->get(1)->configRevision;
        $this->repo->recordPaused(1, $rev, 'store_limit');
        $s = $this->repo->get(1);
        self::assertSame(['paused', 'store_limit', 0, null], [$s->state, $s->reason, $s->attempts, $s->nextAttemptAt]);
        $this->repo->recordBlocked(1, $rev, 'too_many_products');
        self::assertSame(['blocked', 'too_many_products'], [$this->repo->get(1)->state, $this->repo->get(1)->reason]);
    }

    public function testSettingsHashBumpsOnlyOnChangeAndResetsTheView(): void
    {
        $this->set(1, ['state' => 'paused', 'reason' => 'invalid_key', 'attempts' => 0, 'dirty' => 0]);
        self::assertTrue($this->repo->applySettingsHash(1, 'h1', $this->now()));
        $s = $this->repo->get(1);
        self::assertSame([2, 'ok', null, true], [$s->configRevision, $s->state, $s->reason, $s->dirty]);
        self::assertFalse($this->repo->applySettingsHash(1, 'h1', $this->now()));
        self::assertSame(2, $this->repo->get(1)->configRevision);
    }

    public function testAccountAndSnapshot(): void
    {
        $this->repo->recordAccount(1, 'acct_x', 'keyhash');
        $this->repo->saveSnapshot(1, 'fp', '{"a":1}', $this->now());
        $s = $this->repo->get(1);
        self::assertSame(['acct_x', 'keyhash', 'fp', '{"a":1}'],
            [$s->accountId, $s->accountKeyHash, $s->snapshotFingerprint, $s->snapshotJson]);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --testsuite Db --filter SyncStateRepositoryTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the clock, timestamp helper and state value object**

`Model/Support/Clock.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Support;

interface Clock
{
    /** Current time in UTC. */
    public function now(): \DateTimeImmutable;
}
```

`Model/Support/SystemClock.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Support;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
```

`Model/Support/Ts.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Support;

/** UTC DATETIME strings for SQL. */
final class Ts
{
    public static function fmt(\DateTimeImmutable $t): string
    {
        return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function parse(?string $value): ?\DateTimeImmutable
    {
        return ($value === null || $value === '')
            ? null
            : new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
```

`Model/Sync/SyncState.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Support\Ts;

final class SyncState
{
    public function __construct(
        public readonly int $storeId,
        public readonly bool $dirty,
        public readonly ?\DateTimeImmutable $lastChangeAt,
        public readonly string $state,
        public readonly ?string $reason,
        public readonly ?string $retryReason,
        public readonly int $attempts,
        public readonly ?\DateTimeImmutable $nextAttemptAt,
        public readonly int $configRevision,
        public readonly ?string $settingsHash,
        public readonly ?\DateTimeImmutable $lastSyncAt,
        public readonly ?int $productCount,
        public readonly ?string $syncedStoreKey,
        public readonly ?string $accountId,
        public readonly ?string $accountKeyHash,
        public readonly ?string $snapshotJson,
        public readonly ?string $snapshotFingerprint,
        public readonly ?\DateTimeImmutable $snapshotFetchedAt
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $str = static fn (string $k): ?string => $row[$k] === null ? null : (string) $row[$k];
        return new self(
            (int) $row['store_id'],
            (int) $row['dirty'] === 1,
            Ts::parse($str('last_change_at')),
            (string) $row['state'],
            $str('reason'),
            $str('retry_reason'),
            (int) $row['attempts'],
            Ts::parse($str('next_attempt_at')),
            (int) $row['config_revision'],
            $str('settings_hash'),
            Ts::parse($str('last_sync_at')),
            $row['product_count'] === null ? null : (int) $row['product_count'],
            $str('synced_store_key'),
            $str('account_id'),
            $str('account_key_hash'),
            $str('snapshot_json'),
            $str('snapshot_fingerprint'),
            Ts::parse($str('snapshot_fetched_at'))
        );
    }
}
```

- [ ] **Step 4: Write the repository**

`Model/Sync/SyncStateRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Db\SqlExecutor;
use Fullspec\AnswerEng\Model\Support\Ts;

/**
 * The per-view sync state machine (spec §6). Every outcome is applied by ONE
 * UPDATE guarded only by config_revision, so a push that raced a config
 * change is discarded, never half-applied.
 *
 * MySQL evaluates single-table UPDATE assignments LEFT TO RIGHT and later
 * expressions see earlier assignments — assignment order below is deliberate.
 */
final class SyncStateRepository
{
    public const OK = 'ok';
    public const RETRYING = 'retrying';
    public const EXHAUSTED = 'exhausted';
    public const BLOCKED = 'blocked';
    public const PAUSED = 'paused';
    public const DEBOUNCE_SECONDS = 300;
    public const MAX_TRANSIENT_ATTEMPTS = 3;
    private const RETRY_SECONDS = [300, 900];

    public function __construct(private readonly SqlExecutor $db)
    {
    }

    private function t(): string
    {
        return $this->db->table('answereng_sync_state');
    }

    public function ensure(int $storeId): void
    {
        $this->db->execute("INSERT IGNORE INTO {$this->t()} (store_id) VALUES (?)", [$storeId]);
    }

    public function get(int $storeId): SyncState
    {
        $this->ensure($storeId);
        return SyncState::fromRow((array) $this->db->fetchRow("SELECT * FROM {$this->t()} WHERE store_id = ?", [$storeId]));
    }

    /** @param list<int> $storeIds */
    public function markDirty(array $storeIds, \DateTimeImmutable $now): void
    {
        if ($storeIds === []) {
            return;
        }
        $in = $this->placeholders($storeIds);
        // A catalog change resumes views stuck for catalog reasons.
        $this->db->execute(
            "UPDATE {$this->t()} SET state = 'ok', reason = NULL, attempts = 0, next_attempt_at = NULL,
                 retry_reason = NULL
             WHERE store_id IN ($in)
               AND (state = 'exhausted'
                    OR (state = 'paused' AND reason IN ('validation_failed', 'product_limit')))",
            $storeIds
        );
        $values = implode(', ', array_fill(0, count($storeIds), '(?, 1, ?)'));
        $bind = [];
        foreach ($storeIds as $id) {
            $bind[] = $id;
            $bind[] = Ts::fmt($now);
        }
        $this->db->execute(
            "INSERT INTO {$this->t()} (store_id, dirty, last_change_at) VALUES $values
             ON DUPLICATE KEY UPDATE dirty = 1, last_change_at = VALUES(last_change_at)",
            $bind
        );
    }

    public function applySettingsHash(int $storeId, string $hash, \DateTimeImmutable $now): bool
    {
        $this->ensure($storeId);
        return $this->db->execute(
            "UPDATE {$this->t()} SET config_revision = config_revision + 1, settings_hash = ?, dirty = 1,
                 last_change_at = ?, attempts = 0, next_attempt_at = NULL, retry_reason = NULL,
                 reason = NULL, state = 'ok'
             WHERE store_id = ? AND (settings_hash IS NULL OR settings_hash <> ?)",
            [$hash, Ts::fmt($now), $storeId, $hash]
        ) > 0;
    }

    /** @param list<int> $enabledStoreIds @return list<int> */
    public function selectForDebounce(array $enabledStoreIds, \DateTimeImmutable $now): array
    {
        if ($enabledStoreIds === []) {
            return [];
        }
        $cutoff = Ts::fmt($now->modify('-' . self::DEBOUNCE_SECONDS . ' seconds'));
        return $this->ids(
            "SELECT store_id FROM {$this->t()}
             WHERE store_id IN ({$this->placeholders($enabledStoreIds)})
               AND ((state IN ('ok', 'blocked') AND dirty = 1 AND last_change_at <= ?)
                    OR (state = 'retrying' AND next_attempt_at <= ?))
             ORDER BY store_id",
            [...$enabledStoreIds, $cutoff, Ts::fmt($now)]
        );
    }

    /** @param list<int> $enabledStoreIds @return list<int> */
    public function selectForReconcile(array $enabledStoreIds, \DateTimeImmutable $now): array
    {
        foreach ($enabledStoreIds as $id) {
            $this->ensure($id);
        }
        if ($enabledStoreIds === []) {
            return [];
        }
        return $this->ids(
            "SELECT store_id FROM {$this->t()}
             WHERE store_id IN ({$this->placeholders($enabledStoreIds)})
               AND (state IN ('ok', 'blocked', 'exhausted')
                    OR (state = 'retrying' AND next_attempt_at <= ?))
             ORDER BY store_id",
            [...$enabledStoreIds, Ts::fmt($now)]
        );
    }

    public function recordSuccess(
        int $storeId,
        int $revision,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $now,
        int $productCount,
        ?string $storeKey,
        ?string $reason
    ): bool {
        $changed = $this->db->execute(
            "UPDATE {$this->t()} SET
                 dirty = IF(last_change_at IS NULL OR last_change_at < ?, 0, dirty),
                 state = 'ok', reason = ?, retry_reason = NULL, attempts = 0, next_attempt_at = NULL,
                 last_sync_at = ?, product_count = ?, synced_store_key = COALESCE(?, synced_store_key)
             WHERE store_id = ? AND config_revision = ?",
            [Ts::fmt($startedAt), $reason, Ts::fmt($now), $productCount, $storeKey, $storeId, $revision]
        );
        return $this->applied($changed, $storeId, $revision);
    }

    public function recordTransient(int $storeId, int $revision, \DateTimeImmutable $now, ?int $throttleSeconds): bool
    {
        $throttleUntil = $throttleSeconds === null ? null : Ts::fmt($now->modify("+$throttleSeconds seconds"));
        $changed = $this->db->execute(
            // state and next_attempt_at read the OLD attempts; attempts is assigned last.
            "UPDATE {$this->t()} SET
                 state = IF(attempts + 1 >= ?, 'exhausted', 'retrying'),
                 next_attempt_at = IF(attempts + 1 >= ?, NULL, COALESCE(?, IF(attempts = 0, ?, ?))),
                 retry_reason = IF(attempts + 1 >= ?, NULL, ?),
                 attempts = attempts + 1
             WHERE store_id = ? AND config_revision = ?",
            [
                self::MAX_TRANSIENT_ATTEMPTS,
                self::MAX_TRANSIENT_ATTEMPTS, $throttleUntil,
                Ts::fmt($now->modify('+' . self::RETRY_SECONDS[0] . ' seconds')),
                Ts::fmt($now->modify('+' . self::RETRY_SECONDS[1] . ' seconds')),
                self::MAX_TRANSIENT_ATTEMPTS, $throttleSeconds === null ? null : 'throttled',
                $storeId, $revision,
            ]
        );
        return $this->applied($changed, $storeId, $revision);
    }

    public function recordPaused(int $storeId, int $revision, string $reason): bool
    {
        return $this->setTerminal($storeId, $revision, self::PAUSED, $reason);
    }

    public function recordBlocked(int $storeId, int $revision, string $reason): bool
    {
        return $this->setTerminal($storeId, $revision, self::BLOCKED, $reason);
    }

    public function recordAccount(int $storeId, string $accountId, string $apiKeyHash): void
    {
        $this->ensure($storeId);
        $this->db->execute(
            "UPDATE {$this->t()} SET account_id = ?, account_key_hash = ? WHERE store_id = ?",
            [$accountId, $apiKeyHash, $storeId]
        );
    }

    public function throttledUntil(int $storeId, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $value = $this->db->fetchOne(
            "SELECT next_attempt_at FROM {$this->t()}
             WHERE store_id = ? AND state = 'retrying' AND retry_reason = 'throttled' AND next_attempt_at > ?",
            [$storeId, Ts::fmt($now)]
        );
        return Ts::parse($value === null ? null : (string) $value);
    }

    public function saveSnapshot(int $storeId, string $fingerprint, string $json, \DateTimeImmutable $now): void
    {
        $this->ensure($storeId);
        $this->db->execute(
            "UPDATE {$this->t()} SET snapshot_fingerprint = ?, snapshot_json = ?, snapshot_fetched_at = ?
             WHERE store_id = ?",
            [$fingerprint, $json, Ts::fmt($now), $storeId]
        );
    }

    private function setTerminal(int $storeId, int $revision, string $state, string $reason): bool
    {
        $changed = $this->db->execute(
            "UPDATE {$this->t()} SET state = ?, reason = ?, retry_reason = NULL, attempts = 0, next_attempt_at = NULL
             WHERE store_id = ? AND config_revision = ?",
            [$state, $reason, $storeId, $revision]
        );
        return $this->applied($changed, $storeId, $revision);
    }

    /**
     * MySQL reports CHANGED rows, so an UPDATE that rewrote identical values
     * returns 0. Revisions only ever increase, so if the revision still equals
     * the captured one now, it also did when the UPDATE ran: it was applied.
     */
    private function applied(int $changed, int $storeId, int $revision): bool
    {
        if ($changed > 0) {
            return true;
        }
        return (int) $this->db->fetchOne(
            "SELECT config_revision FROM {$this->t()} WHERE store_id = ?",
            [$storeId]
        ) === $revision;
    }

    /** @param list<int> $ids */
    private function placeholders(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '?'));
    }

    /** @param list<scalar|null> $bind @return list<int> */
    private function ids(string $sql, array $bind): array
    {
        return array_map(static fn (array $r) => (int) $r['store_id'], $this->db->fetchAll($sql, $bind));
    }
}
```

Add to `etc/di.xml`:

```xml
    <preference for="Fullspec\AnswerEng\Model\Support\Clock"
                type="Fullspec\AnswerEng\Model\Support\SystemClock"/>
```

- [ ] **Step 5: Run the tests, lint and analyse**

Run: `vendor/bin/phpunit --testsuite Db` then `vendor/bin/phpcs` and `vendor/bin/phpstan analyse --no-progress`
Expected: all PASS / no errors. Note `testReconcileSelection` expects view 9 (no row yet) to be selected: `selectForReconcile` creates rows for enabled views.

- [ ] **Step 6: Commit**

```bash
git add Model/Support/Clock.php Model/Support/SystemClock.php Model/Support/Ts.php Model/Sync/SyncState.php Model/Sync/SyncStateRepository.php Test/Support/FixedClock.php Test/Db/SyncStateRepositoryTest.php etc/di.xml
git commit -m "feat: revision-guarded sync state machine with debounce and retry selection" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Sync log

**Files:**
- Create: `Model/Sync/SyncLog.php`
- Test: `Test/Db/SyncLogTest.php`

**Interfaces:**
- Consumes: `SqlExecutor`, `Ts`.
- Produces: `SyncLog::KEEP = 20`; `append(int $storeId, \DateTimeImmutable $at, string $source, string $outcome, ?string $detail = null, ?string $adminUser = null): void`; `recent(int $storeId, int $limit = 20): array` (newest first, rows with keys `created_at, source, outcome, detail, admin_user`).

- [ ] **Step 1: Write the failing test**

`Test/Db/SyncLogTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Sync\SyncLog;

final class SyncLogTest extends DbTestCase
{
    public function testKeepsTheNewestTwentyPerViewNewestFirst(): void
    {
        $log = new SyncLog($this->db);
        $t = new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('UTC'));
        for ($i = 1; $i <= 25; $i++) {
            $log->append(1, $t->modify("+$i seconds"), 'debounce', "pushed-$i");
        }
        $log->append(2, $t, 'manual', 'pushed', null, 'admin');
        $rows = $log->recent(1);
        self::assertCount(20, $rows);
        self::assertSame('pushed-25', $rows[0]['outcome']);
        self::assertSame('pushed-6', $rows[19]['outcome']);
        self::assertSame('admin', $log->recent(2)[0]['admin_user']);
    }

    public function testDetailIsTruncatedTo1000Characters(): void
    {
        $log = new SyncLog($this->db);
        $log->append(1, new \DateTimeImmutable('2026-10-07 12:00:00'), 'debounce', 'paused', str_repeat('x', 1500));
        self::assertSame(1000, mb_strlen((string) $log->recent(1)[0]['detail']));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --testsuite Db --filter SyncLogTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the log**

`Model/Sync/SyncLog.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Db\SqlExecutor;
use Fullspec\AnswerEng\Model\Support\Ts;

/** Rolling per-view log shown in the admin panel. Detail must already be sanitized. */
final class SyncLog
{
    public const KEEP = 20;

    public function __construct(private readonly SqlExecutor $db)
    {
    }

    public function append(
        int $storeId,
        \DateTimeImmutable $at,
        string $source,
        string $outcome,
        ?string $detail = null,
        ?string $adminUser = null
    ): void {
        $t = $this->db->table('answereng_sync_log');
        $this->db->execute(
            "INSERT INTO $t (store_id, created_at, source, outcome, detail, admin_user) VALUES (?, ?, ?, ?, ?, ?)",
            [$storeId, Ts::fmt($at), $source, $outcome,
                $detail === null ? null : mb_substr($detail, 0, 1000), $adminUser]
        );
        $this->db->execute(
            "DELETE FROM $t WHERE store_id = ? AND log_id NOT IN (
                 SELECT log_id FROM (
                     SELECT log_id FROM $t WHERE store_id = ? ORDER BY log_id DESC LIMIT " . self::KEEP . "
                 ) AS keep_rows)",
            [$storeId, $storeId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $storeId, int $limit = self::KEEP): array
    {
        $t = $this->db->table('answereng_sync_log');
        return $this->db->fetchAll(
            "SELECT created_at, source, outcome, detail, admin_user FROM $t
             WHERE store_id = ? ORDER BY log_id DESC LIMIT " . max(1, min($limit, self::KEEP)),
            [$storeId]
        );
    }
}
```

- [ ] **Step 4: Run, lint, analyse, commit**

Run: `vendor/bin/phpunit --testsuite Db --filter SyncLogTest`, `vendor/bin/phpcs`, `vendor/bin/phpstan analyse --no-progress` — PASS.

```bash
git add Model/Sync/SyncLog.php Test/Db/SyncLogTest.php
git commit -m "feat: rolling per-view sync log" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Settings, store keys and configuration

**Files:**
- Create: `Model/Config/Settings.php`, `Model/Config/SettingsProvider.php`, `Model/Config/ConfigSettingsProvider.php`, `Model/Config/StoreKeyPolicy.php`, `Model/Config/DuplicateStoreKeyException.php`, `Model/Config/Backend/StoreKey.php`, `Model/Config/StoreKeyComment.php`
- Create: `etc/adminhtml/system.xml`, `etc/config.xml`, `etc/acl.xml`
- Create: `Test/Support/FakeSettings.php`
- Test: `Test/Unit/Config/SettingsTest.php`, `Test/Unit/Config/StoreKeyPolicyTest.php`, `Test/Unit/Config/ConfigSettingsProviderTest.php`
- Modify: `etc/di.xml` (SettingsProvider preference)

**Interfaces:**
- Consumes: `Connection` (Task 2), `SyncStateRepository` (Task 5, for the store-key comment and warning).
- Produces: `Settings` (readonly `storeId, enabled, engineUrl, apiKey, storeKey, brandNames, competitors`; methods `hash(): string`, `fingerprint(): string`, `apiKeyHash(): string`, `connection(): Connection`); `SettingsProvider` port: `forStore(int): Settings`, `allStoreIds(): list<int>`, `enabledStoreIds(): list<int>`, `storeIdsForWebsites(list<int>): list<int>`, `storeName(int): string`, `storeIdByCode(string): ?int`; `ConfigSettingsProvider::DEFAULT_ENGINE_URL = 'https://api.answereng.io'` and config path constants `PATH_ENGINE_URL, PATH_API_KEY, PATH_ENABLED, PATH_STORE_KEY, PATH_BRANDS, PATH_COMPETITORS`; `StoreKeyPolicy::normalize(string): string`, `::fromBaseUrl(string): string`, `::assertUnique(string $key, array $otherKeysByStoreId): void` (throws `DuplicateStoreKeyException` with `->storeId`); test double `FakeSettings` implementing `SettingsProvider`.

- [ ] **Step 1: Write the failing tests**

`Test/Unit/Config/SettingsTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Config;

use Fullspec\AnswerEng\Model\Config\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    private function settings(array $o = []): Settings
    {
        return new Settings($o['storeId'] ?? 1, $o['enabled'] ?? true, $o['engineUrl'] ?? 'https://api.answereng.io',
            $o['apiKey'] ?? 'ae_1', $o['storeKey'] ?? 'shop.example.com', $o['brands'] ?? ['Acme'], $o['competitors'] ?? []);
    }

    public function testHashChangesWithAnySettingFingerprintOnlyWithConnection(): void
    {
        $base = $this->settings();
        $brands = $this->settings(['brands' => ['Acme', 'Acme Outdoor']]);
        $key = $this->settings(['apiKey' => 'ae_2']);
        self::assertNotSame($base->hash(), $brands->hash());
        self::assertSame($base->fingerprint(), $brands->fingerprint());
        self::assertNotSame($base->fingerprint(), $key->fingerprint());
        self::assertNotSame($base->hash(), $this->settings(['enabled' => false])->hash());
    }

    public function testApiKeyNeverAppearsInHashesOrFingerprints(): void
    {
        $s = $this->settings(['apiKey' => 'ae_supersecret']);
        self::assertStringNotContainsString('supersecret', $s->hash() . $s->fingerprint() . $s->apiKeyHash());
    }

    public function testConnectionUsesTheEffectiveEngineUrl(): void
    {
        self::assertSame('https://api.answereng.io', $this->settings()->connection()->engineUrl);
    }
}
```

`Test/Unit/Config/StoreKeyPolicyTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Config;

use Fullspec\AnswerEng\Model\Config\DuplicateStoreKeyException;
use Fullspec\AnswerEng\Model\Config\StoreKeyPolicy;
use PHPUnit\Framework\TestCase;

final class StoreKeyPolicyTest extends TestCase
{
    public function testNormalizeTrimsButNeverChangesCase(): void
    {
        self::assertSame('Shop.Example.com', StoreKeyPolicy::normalize("  Shop.Example.com \n"));
    }

    public function testFromBaseUrlTakesTheLowercasedHost(): void
    {
        self::assertSame('www.acme-outdoor.co.uk', StoreKeyPolicy::fromBaseUrl('https://WWW.Acme-Outdoor.co.uk/en/'));
        self::assertSame('', StoreKeyPolicy::fromBaseUrl('not a url'));
    }

    public function testUniquenessComparesTrimmedValuesExactly(): void
    {
        StoreKeyPolicy::assertUnique('shop.example.com', [2 => 'Shop.example.com', 3 => 'other']);
        try {
            StoreKeyPolicy::assertUnique('shop.example.com', [2 => 'other', 3 => ' shop.example.com ']);
            self::fail('expected a duplicate');
        } catch (DuplicateStoreKeyException $e) {
            self::assertSame(3, $e->storeId);
        }
    }
}
```

`Test/Unit/Config/ConfigSettingsProviderTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Config;

use Fullspec\AnswerEng\Model\Config\ConfigSettingsProvider as P;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

final class ConfigSettingsProviderTest extends TestCase
{
    public function testReadsScopedValuesDecryptsTheKeyAndAppliesTheHostedDefault(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            static fn (string $path, string $scope = 'default', $scopeId = null) => match ($path) {
                P::PATH_ENGINE_URL => '  ',
                P::PATH_API_KEY => 'encrypted',
                P::PATH_ENABLED => '1',
                P::PATH_STORE_KEY => ' shop.example.com ',
                P::PATH_BRANDS => "Acme\n\n  Acme Outdoor \nAcme",
                P::PATH_COMPETITORS => "Merrell\r\nSalomon",
                default => null,
            }
        );
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->with('encrypted')->willReturn('ae_live');
        $p = new P($config, $encryptor, $this->createMock(StoreManagerInterface::class));
        $s = $p->forStore(3);
        self::assertSame([3, true, 'https://api.answereng.io', 'ae_live', 'shop.example.com', ['Acme', 'Acme Outdoor'],
            ['Merrell', 'Salomon']],
            [$s->storeId, $s->enabled, $s->engineUrl, $s->apiKey, $s->storeKey, $s->brandNames, $s->competitors]);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --testsuite Unit --filter 'Config'`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the settings value object, port and policy**

`Model/Config/Settings.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

use Fullspec\AnswerEng\Model\Engine\Connection;

/** Effective AnswerEng settings for one store view (spec §3). */
final class Settings
{
    /**
     * @param list<string> $brandNames
     * @param list<string> $competitors
     */
    public function __construct(
        public readonly int $storeId,
        public readonly bool $enabled,
        public readonly string $engineUrl,
        public readonly string $apiKey,
        public readonly string $storeKey,
        public readonly array $brandNames,
        public readonly array $competitors
    ) {
    }

    /** Changes whenever any setting changes (drives config_revision bumps). */
    public function hash(): string
    {
        return hash('sha256', json_encode([$this->enabled, $this->engineUrl, $this->apiKeyHash(),
            $this->storeKey, $this->brandNames, $this->competitors], JSON_THROW_ON_ERROR));
    }

    /** Identifies the connection whose results may be shown (spec §7 snapshot identity). */
    public function fingerprint(): string
    {
        return hash('sha256', $this->engineUrl . "\n" . $this->apiKeyHash() . "\n" . $this->storeKey);
    }

    public function apiKeyHash(): string
    {
        return hash('sha256', $this->apiKey);
    }

    public function connection(): Connection
    {
        return new Connection($this->engineUrl, $this->apiKey);
    }
}
```

`Model/Config/SettingsProvider.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

interface SettingsProvider
{
    public function forStore(int $storeId): Settings;

    /** @return list<int> every store view id (excluding admin) */
    public function allStoreIds(): array;

    /** @return list<int> */
    public function enabledStoreIds(): array;

    /** @param list<int> $websiteIds @return list<int> enabled store views on those websites */
    public function storeIdsForWebsites(array $websiteIds): array;

    public function storeName(int $storeId): string;

    public function storeIdByCode(string $code): ?int;
}
```

`Model/Config/DuplicateStoreKeyException.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

final class DuplicateStoreKeyException extends \RuntimeException
{
    public function __construct(public readonly int $storeId, string $key)
    {
        parent::__construct("store key \"$key\" is already used by store view $storeId");
    }
}
```

`Model/Config/StoreKeyPolicy.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

/** Store keys are engine identities: trimmed, never case-folded, unique across enabled views. */
final class StoreKeyPolicy
{
    public static function normalize(string $key): string
    {
        return trim($key);
    }

    public static function fromBaseUrl(string $baseUrl): string
    {
        $host = parse_url(trim($baseUrl), PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }

    /** @param array<int,string> $otherKeysByStoreId */
    public static function assertUnique(string $key, array $otherKeysByStoreId): void
    {
        $key = self::normalize($key);
        foreach ($otherKeysByStoreId as $storeId => $other) {
            if ($key !== '' && self::normalize($other) === $key) {
                throw new DuplicateStoreKeyException((int) $storeId, $key);
            }
        }
    }
}
```

- [ ] **Step 4: Write the Magento settings provider**

`Model/Config/ConfigSettingsProvider.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

final class ConfigSettingsProvider implements SettingsProvider
{
    public const DEFAULT_ENGINE_URL = 'https://api.answereng.io';
    public const PATH_ENGINE_URL = 'answereng/connection/engine_url';
    public const PATH_API_KEY = 'answereng/connection/api_key';
    public const PATH_ENABLED = 'answereng/store/enabled';
    public const PATH_STORE_KEY = 'answereng/store/store_key';
    public const PATH_BRANDS = 'answereng/brand/brand_names';
    public const PATH_COMPETITORS = 'answereng/brand/competitors';

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly EncryptorInterface $encryptor,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function forStore(int $storeId): Settings
    {
        $engineUrl = trim((string) $this->config->getValue(self::PATH_ENGINE_URL));
        $encrypted = trim((string) $this->config->getValue(self::PATH_API_KEY));
        return new Settings(
            $storeId,
            (bool) $this->storeValue(self::PATH_ENABLED, $storeId),
            rtrim($engineUrl === '' ? self::DEFAULT_ENGINE_URL : $engineUrl, '/'),
            $encrypted === '' ? '' : trim($this->encryptor->decrypt($encrypted)),
            StoreKeyPolicy::normalize((string) $this->storeValue(self::PATH_STORE_KEY, $storeId)),
            $this->lines((string) $this->storeValue(self::PATH_BRANDS, $storeId)),
            $this->lines((string) $this->storeValue(self::PATH_COMPETITORS, $storeId))
        );
    }

    public function allStoreIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            $ids[] = (int) $store->getId();
        }
        sort($ids);
        return $ids;
    }

    public function enabledStoreIds(): array
    {
        return array_values(array_filter($this->allStoreIds(),
            fn (int $id) => (bool) $this->storeValue(self::PATH_ENABLED, $id)));
    }

    public function storeIdsForWebsites(array $websiteIds): array
    {
        $ids = [];
        foreach (array_unique($websiteIds) as $websiteId) {
            foreach ($this->storeManager->getWebsite((int) $websiteId)->getStoreIds() as $storeId) {
                $ids[] = (int) $storeId;
            }
        }
        return array_values(array_intersect($this->enabledStoreIds(), $ids));
    }

    public function storeName(int $storeId): string
    {
        $store = $this->storeManager->getStore($storeId);
        return $store->getName() . ' (' . $store->getCode() . ')';
    }

    public function storeIdByCode(string $code): ?int
    {
        foreach ($this->storeManager->getStores(false) as $store) {
            if ($store->getCode() === $code) {
                return (int) $store->getId();
            }
        }
        return null;
    }

    private function storeValue(string $path, int $storeId): mixed
    {
        return $this->config->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /** @return list<string> */
    private function lines(string $value): array
    {
        $out = [];
        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !in_array($line, $out, true)) {
                $out[] = $line;
            }
        }
        return $out;
    }
}
```

Add to `etc/di.xml`:

```xml
    <preference for="Fullspec\AnswerEng\Model\Config\SettingsProvider"
                type="Fullspec\AnswerEng\Model\Config\ConfigSettingsProvider"/>
```

- [ ] **Step 5: Write the test double used by later tasks**

`Test/Support/FakeSettings.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Support;

use Fullspec\AnswerEng\Model\Config\Settings;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;

final class FakeSettings implements SettingsProvider
{
    /** @var array<int,Settings> */
    public array $stores = [];
    /** @var array<int,int> storeId => websiteId */
    public array $websites = [];

    public function put(int $storeId, array $o = [], int $websiteId = 1): Settings
    {
        $this->websites[$storeId] = $websiteId;
        return $this->stores[$storeId] = new Settings(
            $storeId,
            $o['enabled'] ?? true,
            $o['engineUrl'] ?? 'https://engine.test',
            $o['apiKey'] ?? 'ae_live',
            $o['storeKey'] ?? "store$storeId.example.com",
            $o['brands'] ?? ['Acme'],
            $o['competitors'] ?? ['Rival']
        );
    }

    public function forStore(int $storeId): Settings
    {
        return $this->stores[$storeId];
    }

    public function allStoreIds(): array
    {
        $ids = array_keys($this->stores);
        sort($ids);
        return $ids;
    }

    public function enabledStoreIds(): array
    {
        return array_values(array_filter($this->allStoreIds(), fn ($id) => $this->stores[$id]->enabled));
    }

    public function storeIdsForWebsites(array $websiteIds): array
    {
        return array_values(array_filter($this->enabledStoreIds(),
            fn ($id) => in_array($this->websites[$id], $websiteIds, true)));
    }

    public function storeName(int $storeId): string
    {
        return "Store $storeId";
    }

    public function storeIdByCode(string $code): ?int
    {
        return preg_match('/^store(\d+)$/', $code, $m) ? (int) $m[1] : null;
    }
}
```

- [ ] **Step 6: Write the admin configuration**

`etc/acl.xml`:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Acl/etc/acl.xsd">
    <acl>
        <resources>
            <resource id="Magento_Backend::admin">
                <resource id="Magento_Backend::stores">
                    <resource id="Magento_Backend::stores_settings">
                        <resource id="Magento_Config::config">
                            <resource id="Fullspec_AnswerEng::config" title="AnswerEng" translate="title" sortOrder="200">
                                <resource id="Fullspec_AnswerEng::sync" title="AnswerEng: sync now and clear catalog" translate="title" sortOrder="10"/>
                                <resource id="Fullspec_AnswerEng::delete_store" title="AnswerEng: delete stores (Danger zone)" translate="title" sortOrder="20"/>
                            </resource>
                        </resource>
                    </resource>
                </resource>
            </resource>
        </resources>
    </acl>
</config>
```

`etc/config.xml`:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Store:etc/config.xsd">
    <default>
        <answereng>
            <connection>
                <engine_url/>
                <api_key backend_model="Magento\Config\Model\Config\Backend\Encrypted"/>
            </connection>
            <store>
                <enabled>0</enabled>
            </store>
        </answereng>
    </default>
</config>
```

`etc/adminhtml/system.xml` (the status panel group is added in Task 14):

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Config:etc/system_file.xsd">
    <system>
        <section id="answereng" translate="label" sortOrder="900" showInDefault="1" showInWebsite="1" showInStore="1">
            <label>AnswerEng</label>
            <tab>catalog</tab>
            <resource>Fullspec_AnswerEng::config</resource>
            <group id="connection" translate="label comment" sortOrder="20" showInDefault="1" showInWebsite="0" showInStore="0">
                <label>Connection</label>
                <comment>One AnswerEng workspace key covers every store view. Catalog data (SKU, name, description, price, category, storefront attributes) is sent to the engine below.</comment>
                <field id="engine_url" translate="label comment" type="text" sortOrder="10" showInDefault="1" showInWebsite="0" showInStore="0">
                    <label>Engine URL</label>
                    <comment>Leave blank to use the hosted engine (https://api.answereng.io).</comment>
                    <validate>validate-url</validate>
                </field>
                <field id="api_key" translate="label comment" type="obscure" sortOrder="20" showInDefault="1" showInWebsite="0" showInStore="0">
                    <label>API key</label>
                    <comment>Get a key at https://answereng.io/signup. Stored encrypted.</comment>
                    <backend_model>Magento\Config\Model\Config\Backend\Encrypted</backend_model>
                </field>
            </group>
            <group id="store" translate="label comment" sortOrder="30" showInDefault="0" showInWebsite="0" showInStore="1">
                <label>This store view</label>
                <comment>Each enabled store view is a separate AnswerEng store.</comment>
                <field id="enabled" translate="label" type="select" sortOrder="10" showInDefault="0" showInWebsite="0" showInStore="1">
                    <label>Enabled for AnswerEng</label>
                    <source_model>Magento\Config\Model\Config\Source\Yesno</source_model>
                </field>
                <field id="store_key" translate="label" type="text" sortOrder="20" showInDefault="0" showInWebsite="0" showInStore="1">
                    <label>Store key</label>
                    <backend_model>Fullspec\AnswerEng\Model\Config\Backend\StoreKey</backend_model>
                    <comment>
                        <model>Fullspec\AnswerEng\Model\Config\StoreKeyComment</model>
                    </comment>
                </field>
            </group>
            <group id="brand" translate="label comment" sortOrder="40" showInDefault="1" showInWebsite="1" showInStore="1">
                <label>Brand</label>
                <comment>One name per line. Store views inherit these from the website or default scope unless overridden.</comment>
                <field id="brand_names" translate="label" type="textarea" sortOrder="10" showInDefault="1" showInWebsite="1" showInStore="1">
                    <label>Brand names</label>
                </field>
                <field id="competitors" translate="label" type="textarea" sortOrder="20" showInDefault="1" showInWebsite="1" showInStore="1">
                    <label>Competitors</label>
                </field>
            </group>
        </section>
    </system>
</config>
```

- [ ] **Step 7: Write the store-key backend model and comment**

`Model/Config/Backend/StoreKey.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config\Backend;

use Fullspec\AnswerEng\Model\Config\DuplicateStoreKeyException;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Config\StoreKeyPolicy;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Spec §3: the store key is persisted. A blank key on an enabled view is
 * filled ONCE from the view's base-URL host; later base-URL changes never
 * move it. Keys must be unique across enabled views.
 */
class StoreKey extends Value
{
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly StoreManagerInterface $storeManager,
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly ManagerInterface $messages,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave()
    {
        $storeId = (int) $this->getScopeId();
        $key = StoreKeyPolicy::normalize((string) $this->getValue());
        $enabled = (bool) $this->getFieldsetDataValue('enabled');
        if ($key === '' && $enabled) {
            $key = StoreKeyPolicy::fromBaseUrl((string) $this->storeManager->getStore($storeId)->getBaseUrl());
        }
        if ($enabled) {
            $others = [];
            foreach ($this->settings->enabledStoreIds() as $otherId) {
                if ($otherId !== $storeId) {
                    $others[$otherId] = $this->settings->forStore($otherId)->storeKey;
                }
            }
            try {
                StoreKeyPolicy::assertUnique($key, $others);
            } catch (DuplicateStoreKeyException $e) {
                throw new LocalizedException(__(
                    'AnswerEng store key "%1" is already used by store view %2. Store keys must be unique.',
                    $key,
                    $this->settings->storeName($e->storeId)
                ));
            }
        }
        $this->setValue($key);
        return parent::beforeSave();
    }

    public function afterSave()
    {
        $synced = $this->state->get((int) $this->getScopeId())->syncedStoreKey;
        if ($this->isValueChanged() && $synced !== null && $synced !== (string) $this->getValue()) {
            $this->messages->addWarningMessage(__(
                'This store view had synced to AnswerEng as "%1". The new key registers a NEW AnswerEng store that '
                . 'counts against your plan; "%1" keeps its data and its slot until you delete it in the Danger zone.',
                $synced
            ));
        }
        return parent::afterSave();
    }
}
```

`Model/Config/StoreKeyComment.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Magento\Config\Model\Config\CommentInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Escaper;

/** Field comment for the store key: the warning before it changes (spec §3). */
final class StoreKeyComment implements CommentInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly SyncStateRepository $state,
        private readonly Escaper $escaper
    ) {
    }

    public function getCommentText($elementValue)
    {
        $default = (string) __('Leave blank to use this store view\'s domain when you enable it. '
            . 'Each key is a separate AnswerEng store.');
        $storeId = (int) $this->request->getParam('store');
        if ($storeId <= 0) {
            return $default;
        }
        $synced = $this->state->get($storeId)->syncedStoreKey;
        if ($synced === null) {
            return $default;
        }
        return (string) __('This view has synced as "%1". Changing it registers a NEW store in AnswerEng that counts '
            . 'against your plan; the old store keeps its data and its slot until you delete it (Danger zone).',
            $this->escaper->escapeHtml($synced));
    }
}
```

- [ ] **Step 8: Run tests, lint, analyse, commit**

Run: `vendor/bin/phpunit --testsuite Unit` then `vendor/bin/phpcs` and `vendor/bin/phpstan analyse --no-progress`
Expected: PASS / no errors.

```bash
git add Model/Config etc/adminhtml/system.xml etc/config.xml etc/acl.xml etc/di.xml Test/Support/FakeSettings.php Test/Unit/Config
git commit -m "feat: per-view settings, persisted unique store keys and admin configuration" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Catalog building

**Files:**
- Create: `Model/Catalog/ProductRecord.php`, `Model/Catalog/CatalogSource.php`, `Model/Catalog/ExportResult.php`, `Model/Catalog/TextCleaner.php`, `Model/Catalog/CatalogBuilder.php`, `Model/Catalog/MagentoCatalogSource.php`
- Create: `Test/Support/FakeCatalogSource.php`
- Test: `Test/Unit/Catalog/CatalogBuilderTest.php`, `Test/Unit/Catalog/TextCleanerTest.php`
- Modify: `etc/di.xml` (CatalogSource preference)

**Interfaces:**
- Consumes: `Settings` (Task 7).
- Produces: `ProductRecord(string $sku, string $name, string $shortDescription, string $description, ?float $price, string $category, array $attributes)`; `CatalogSource::eligibleIds(int $storeId): list<int>`, `::loadChunk(int $storeId, list<int> $ids): list<ProductRecord>`; `ExportResult` with `kind` (`ExportResult::PAYLOAD|EMPTY|TOO_MANY`), `json: string`, `count: int`; `CatalogBuilder::MAX_PRODUCTS = 5000`, `::CHUNK = 500`, `build(Settings $s): ExportResult` (exceptions from the source propagate — nothing partial is returned); `TextCleaner::plain(string): string`; test double `FakeCatalogSource`.

- [ ] **Step 1: Write the test double and failing tests**

`Test/Support/FakeCatalogSource.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Support;

use Fullspec\AnswerEng\Model\Catalog\CatalogSource;
use Fullspec\AnswerEng\Model\Catalog\ProductRecord;

final class FakeCatalogSource implements CatalogSource
{
    /** @var array<int,array<int,ProductRecord>> storeId => [entityId => record] */
    public array $products = [];
    /** @var list<list<int>> */
    public array $chunks = [];
    public ?\Throwable $failOnChunk = null;
    /** @var list<int> ids that vanish between id capture and chunk load */
    public array $deletedMidway = [];

    public function add(int $storeId, int $entityId, ?ProductRecord $r = null): void
    {
        $this->products[$storeId][$entityId] = $r ?? new ProductRecord("SKU-$entityId", "Product $entityId",
            'Short', 'Long', 10.0, 'boots', []);
    }

    public function eligibleIds(int $storeId): array
    {
        return array_keys($this->products[$storeId] ?? []);
    }

    public function loadChunk(int $storeId, array $ids): array
    {
        $this->chunks[] = $ids;
        if ($this->failOnChunk !== null && count($this->chunks) > 1) {
            throw $this->failOnChunk;
        }
        $out = [];
        foreach ($ids as $id) {
            if (!in_array($id, $this->deletedMidway, true) && isset($this->products[$storeId][$id])) {
                $out[] = $this->products[$storeId][$id];
            }
        }
        return $out;
    }
}
```

`Test/Unit/Catalog/TextCleanerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Catalog;

use Fullspec\AnswerEng\Model\Catalog\TextCleaner;
use PHPUnit\Framework\TestCase;

final class TextCleanerTest extends TestCase
{
    public function testStripsTagsDecodesEntitiesAndCollapsesWhitespace(): void
    {
        self::assertSame('Waterproof & warm — 2 layers 🥾',
            TextCleaner::plain("<p>Waterproof &amp; warm&nbsp;&mdash;<br>2\n\tlayers 🥾</p>"));
    }
}
```

`Test/Unit/Catalog/CatalogBuilderTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Catalog;

use Fullspec\AnswerEng\Model\Catalog\CatalogBuilder;
use Fullspec\AnswerEng\Model\Catalog\ExportResult;
use Fullspec\AnswerEng\Model\Catalog\ProductRecord;
use Fullspec\AnswerEng\Model\Config\Settings;
use Fullspec\AnswerEng\Test\Support\FakeCatalogSource;
use PHPUnit\Framework\TestCase;

final class CatalogBuilderTest extends TestCase
{
    private FakeCatalogSource $source;
    private Settings $settings;

    protected function setUp(): void
    {
        $this->source = new FakeCatalogSource();
        $this->settings = new Settings(1, true, 'https://e', 'ae', 'demo-outdoor-store', ['Acme Outdoor'],
            ['Merrell', 'Salomon', 'KEEN']);
    }

    private function decode(ExportResult $r): array
    {
        return json_decode($r->json, true, 64, JSON_THROW_ON_ERROR);
    }

    public function testPayloadKeyOrderMatchesTheFrozenContract(): void
    {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/catalog_push.json'), true);
        $this->source->add(1, 7, new ProductRecord('ACME-TRAIL-2', 'Acme Trail 2', '', 'Long', 139.99, 'hiking-boots',
            ['waterproof' => 'yes']));
        $payload = $this->decode((new CatalogBuilder($this->source))->build($this->settings));
        self::assertSame(array_keys($fixture), array_keys($payload));
        self::assertSame(array_keys($fixture['products'][0]), array_keys($payload['products'][0]));
        self::assertSame(['demo-outdoor-store', ['Acme Outdoor'], ['Merrell', 'Salomon', 'KEEN']],
            [$payload['store_key'], $payload['brand_names'], $payload['competitors']]);
    }

    public function testMappingFallbacksAndEmptyAttributesEncodeAsAnObject(): void
    {
        $this->source->add(1, 1, new ProductRecord('S1', 'Boot', '', '<p>Long &amp; warm</p>', null, '',
            ['color' => '  ', 'size' => '']));
        $result = (new CatalogBuilder($this->source))->build($this->settings);
        self::assertStringContainsString('"attributes":{}', $result->json);
        $p = $this->decode($result)['products'][0];
        self::assertSame(['S1', 'Boot', 'Long & warm', null, ''],
            [$p['sku'], $p['title'], $p['description'], $p['price'], $p['category']]);
    }

    public function testShortDescriptionWinsWhenPresent(): void
    {
        $this->source->add(1, 1, new ProductRecord('S1', 'Boot', '<b>Short</b>', 'Long', 5.0, 'c', ['fit' => 'wide']));
        $p = $this->decode((new CatalogBuilder($this->source))->build($this->settings))['products'][0];
        self::assertSame(['Short', ['fit' => 'wide']], [$p['description'], $p['attributes']]);
    }

    public function testInvalidUtf8AndEmojiNeverBreakEncoding(): void
    {
        $this->source->add(1, 1, new ProductRecord("S\xC3\x28", "Bad \xFF name 🥾", '', '', 1.0, 'c', []));
        $result = (new CatalogBuilder($this->source))->build($this->settings);
        self::assertSame(1, $result->count);
        self::assertStringContainsString('🥾', $this->decode($result)['products'][0]['title']);
    }

    public function testMoreThan5000IsRefusedWithoutLoadingAnything(): void
    {
        for ($i = 1; $i <= 5001; $i++) {
            $this->source->add(1, $i);
        }
        $result = (new CatalogBuilder($this->source))->build($this->settings);
        self::assertSame([ExportResult::TOO_MANY, 5001], [$result->kind, $result->count]);
        self::assertSame([], $this->source->chunks);
    }

    public function testNoEligibleProductsIsEmpty(): void
    {
        self::assertSame(ExportResult::EMPTY, (new CatalogBuilder($this->source))->build($this->settings)->kind);
    }

    public function testLoadsCapturedIdsInChunksOf500AndDropsProductsDeletedMidway(): void
    {
        for ($i = 1; $i <= 1100; $i++) {
            $this->source->add(1, $i);
        }
        $this->source->deletedMidway = [5, 600];
        $result = (new CatalogBuilder($this->source))->build($this->settings);
        self::assertSame([500, 500, 100], array_map('count', $this->source->chunks));
        self::assertSame(1098, $result->count);
    }

    public function testAllProductsVanishingMidwayIsEmpty(): void
    {
        $this->source->add(1, 1);
        $this->source->deletedMidway = [1];
        self::assertSame(ExportResult::EMPTY, (new CatalogBuilder($this->source))->build($this->settings)->kind);
    }

    public function testAChunkFailureAbortsTheWholeExport(): void
    {
        for ($i = 1; $i <= 600; $i++) {
            $this->source->add(1, $i);
        }
        $this->source->failOnChunk = new \RuntimeException('db gone');
        $this->expectException(\RuntimeException::class);
        (new CatalogBuilder($this->source))->build($this->settings);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --testsuite Unit --filter 'Catalog'`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the catalog core**

`Model/Catalog/ProductRecord.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Catalog;

/** Raw, store-view-scoped product data from a CatalogSource. */
final class ProductRecord
{
    /** @param array<string,string> $attributes attribute code => storefront text */
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly string $shortDescription,
        public readonly string $description,
        public readonly ?float $price,
        public readonly string $category,
        public readonly array $attributes
    ) {
    }
}
```

`Model/Catalog/CatalogSource.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Catalog;

interface CatalogSource
{
    /**
     * Ids of products eligible for this store view: enabled, visible
     * individually (catalog, search or both), assigned to its website.
     *
     * @return list<int>
     */
    public function eligibleIds(int $storeId): array;

    /**
     * Load the given ids (some may have vanished — they are simply absent).
     *
     * @param list<int> $ids
     * @return list<ProductRecord>
     */
    public function loadChunk(int $storeId, array $ids): array;
}
```

`Model/Catalog/ExportResult.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Catalog;

final class ExportResult
{
    public const PAYLOAD = 'payload';
    public const EMPTY = 'empty';
    public const TOO_MANY = 'too_many';

    private function __construct(public readonly string $kind, public readonly string $json, public readonly int $count)
    {
    }

    public static function payload(string $json, int $count): self
    {
        return new self(self::PAYLOAD, $json, $count);
    }

    public static function empty(): self
    {
        return new self(self::EMPTY, '', 0);
    }

    public static function tooMany(int $count): self
    {
        return new self(self::TOO_MANY, '', $count);
    }
}
```

`Model/Catalog/TextCleaner.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Catalog;

final class TextCleaner
{
    public static function plain(string $html): string
    {
        $text = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
```

`Model/Catalog/CatalogBuilder.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Catalog;

use Fullspec\AnswerEng\Model\Config\Settings;

/**
 * Spec §4: capture the eligible id set once, guard on its size, then load
 * the captured ids in chunks. Any chunk failure propagates — a partial
 * catalog is never returned (ingestion is full-replace).
 */
final class CatalogBuilder
{
    public const MAX_PRODUCTS = 5000;
    public const CHUNK = 500;
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(private readonly CatalogSource $source)
    {
    }

    public function build(Settings $s): ExportResult
    {
        $ids = array_values(array_unique(array_map('intval', $this->source->eligibleIds($s->storeId))));
        sort($ids, SORT_NUMERIC);
        if (count($ids) > self::MAX_PRODUCTS) {
            return ExportResult::tooMany(count($ids));
        }
        if ($ids === []) {
            return ExportResult::empty();
        }
        $products = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach ($this->source->loadChunk($s->storeId, $chunk) as $record) {
                $products[] = $this->map($record);
            }
        }
        if ($products === []) {
            return ExportResult::empty();
        }
        if (count($products) > self::MAX_PRODUCTS) {
            return ExportResult::tooMany(count($products));
        }
        $payload = [
            'store_key' => $s->storeKey,
            'brand_names' => $s->brandNames,
            'competitors' => $s->competitors,
            'products' => $products,
        ];
        return ExportResult::payload(json_encode($payload, self::JSON_FLAGS), count($products));
    }

    /** @return array<string,mixed> keys in contract order */
    private function map(ProductRecord $r): array
    {
        $short = TextCleaner::plain($r->shortDescription);
        $attributes = [];
        foreach ($r->attributes as $code => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $attributes[(string) $code] = $value;
            }
        }
        return [
            'sku' => $r->sku,
            'title' => $r->name,
            'description' => $short !== '' ? $short : TextCleaner::plain($r->description),
            'price' => $r->price,
            'category' => $r->category,
            'attributes' => $attributes === [] ? new \stdClass() : $attributes,
        ];
    }
}
```

- [ ] **Step 4: Write the Magento catalog source**

`Model/Catalog/MagentoCatalogSource.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Catalog;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Customer\Model\Group;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Spec §4 mapping, store-view scoped:
 * - price: min_price from catalog_product_index_price for the view's website
 *   and NOT LOGGED IN (null when not indexed);
 * - category: url_key of the deepest assigned category under the view's root
 *   (ties: lowest category id);
 * - attributes: is_visible_on_front, keyed by code, storefront text.
 */
final class MagentoCatalogSource implements CatalogSource
{
    /** @var list<string>|null */
    private ?array $visibleCodes = null;

    public function __construct(
        private readonly ProductCollectionFactory $products,
        private readonly CategoryCollectionFactory $categories,
        private readonly AttributeCollectionFactory $attributes,
        private readonly Visibility $visibility,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource
    ) {
    }

    public function eligibleIds(int $storeId): array
    {
        $collection = $this->products->create();
        $collection->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => $this->visibility->getVisibleInSiteIds()]);
        return array_map('intval', $collection->getAllIds());
    }

    public function loadChunk(int $storeId, array $ids): array
    {
        $store = $this->storeManager->getStore($storeId);
        $codes = $this->visibleCodes();
        $collection = $this->products->create();
        $collection->setStoreId($storeId)
            ->addIdFilter($ids)
            ->addAttributeToSelect(array_merge(['name', 'short_description', 'description'], $codes))
            ->addCategoryIds();
        $prices = $this->prices((int) $store->getWebsiteId(), $ids);
        $categoryUrlKeys = $this->categoryKeys($storeId, (int) $store->getRootCategoryId(), $collection->getItems());

        $records = [];
        foreach ($collection->getItems() as $product) {
            $attributes = [];
            foreach ($codes as $code) {
                $attribute = $product->getResource()->getAttribute($code);
                $value = $attribute ? $attribute->getFrontend()->getValue($product) : null;
                if ($value !== null && $value !== false && (string) $value !== '') {
                    $attributes[$code] = (string) $value;
                }
            }
            $id = (int) $product->getId();
            $records[] = new ProductRecord(
                (string) $product->getSku(),
                (string) $product->getName(),
                (string) $product->getData('short_description'),
                (string) $product->getData('description'),
                $prices[$id] ?? null,
                $categoryUrlKeys[$id] ?? '',
                $attributes
            );
        }
        return $records;
    }

    /** @return list<string> */
    private function visibleCodes(): array
    {
        if ($this->visibleCodes === null) {
            $collection = $this->attributes->create()->addFieldToFilter('is_visible_on_front', 1);
            $this->visibleCodes = [];
            foreach ($collection->getItems() as $attribute) {
                $this->visibleCodes[] = (string) $attribute->getAttributeCode();
            }
        }
        return $this->visibleCodes;
    }

    /** @param list<int> $ids @return array<int,float> */
    private function prices(int $websiteId, array $ids): array
    {
        $conn = $this->resource->getConnection();
        $select = $conn->select()
            ->from($this->resource->getTableName('catalog_product_index_price'), ['entity_id', 'min_price'])
            ->where('website_id = ?', $websiteId)
            ->where('customer_group_id = ?', Group::NOT_LOGGED_IN_ID)
            ->where('entity_id IN (?)', $ids);
        $out = [];
        foreach ($conn->fetchAll($select) as $row) {
            if ($row['min_price'] !== null) {
                $out[(int) $row['entity_id']] = (float) $row['min_price'];
            }
        }
        return $out;
    }

    /**
     * @param array<\Magento\Catalog\Model\Product> $products
     * @return array<int,string> product id => category url_key
     */
    private function categoryKeys(int $storeId, int $rootId, array $products): array
    {
        $allIds = [];
        foreach ($products as $product) {
            foreach ((array) $product->getCategoryIds() as $categoryId) {
                $allIds[(int) $categoryId] = true;
            }
        }
        if ($allIds === []) {
            return [];
        }
        $collection = $this->categories->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect('url_key')
            ->addIdFilter(array_keys($allIds));
        $categories = [];
        $prefix = '1/' . $rootId . '/';
        foreach ($collection->getItems() as $category) {
            if (str_starts_with((string) $category->getPath() . '/', $prefix)) {
                $categories[(int) $category->getId()] = $category;
            }
        }
        $out = [];
        foreach ($products as $product) {
            $best = null;
            foreach ((array) $product->getCategoryIds() as $categoryId) {
                $c = $categories[(int) $categoryId] ?? null;
                if ($c === null) {
                    continue;
                }
                if ($best === null || (int) $c->getLevel() > (int) $best->getLevel()
                    || ((int) $c->getLevel() === (int) $best->getLevel() && (int) $c->getId() < (int) $best->getId())) {
                    $best = $c;
                }
            }
            $out[(int) $product->getId()] = $best === null ? '' : (string) $best->getData('url_key');
        }
        return $out;
    }
}
```

Add `"magento/module-customer": "*"` to `composer.json` `require` (for `Group::NOT_LOGGED_IN_ID`), run `composer update magento/module-customer --no-interaction`, and add to `etc/di.xml`:

```xml
    <preference for="Fullspec\AnswerEng\Model\Catalog\CatalogSource"
                type="Fullspec\AnswerEng\Model\Catalog\MagentoCatalogSource"/>
```

Spec note: spec §4 says "deepest category on the product's first category path". Magento has no ordered "first path", so this implementation takes the deepest category under the view's root (ties → lowest id), which is deterministic. That decision is recorded here and in the module README.

- [ ] **Step 5: Run tests, lint, analyse, commit**

Run: `vendor/bin/phpunit --testsuite Unit` then `vendor/bin/phpcs` and `vendor/bin/phpstan analyse --no-progress`
Expected: PASS / no errors.

```bash
git add Model/Catalog Test/Support/FakeCatalogSource.php Test/Unit/Catalog composer.json composer.lock etc/di.xml
git commit -m "feat: consistent catalog export with guards and Magento catalog source" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Remote store registry and derived roles

**Files:**
- Create: `Model/Registry/RemoteStore.php`, `Model/Registry/RemoteStoreRegistry.php`, `Model/Registry/RoleResolver.php`
- Test: `Test/Db/RemoteStoreRegistryTest.php`

**Interfaces:**
- Consumes: `SqlExecutor`, `Ts`, `SettingsProvider` + `Settings` (Task 7), `SyncStateRepository` (Task 5).
- Produces: `RemoteStore` (readonly `id, engineUrl, accountId, storeKey, remoteStatus, purgeAfter: ?\DateTimeImmutable, lastSyncedAt: ?\DateTimeImmutable`); `RemoteStoreRegistry`: `recordPush(string $engineUrl, string $accountId, string $storeKey, int $storeId, \DateTimeImmutable $now): int`, `forView(int $storeId): list<RemoteStore>`, `get(int $id): ?RemoteStore`, `viewsOf(int $id): list<int>`, `setStatus(int $id, string $status, ?\DateTimeImmutable $purgeAfter): void`, `forget(int $id, int $storeId): void`; `RoleResolver`: `isCurrent(RemoteStore $r, int $storeId): bool`, `currentFor(int $storeId): ?RemoteStore`, `activeUsers(int $identityId): list<int>`.

- [ ] **Step 1: Write the failing tests**

`Test/Db/RemoteStoreRegistryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Registry\RemoteStoreRegistry;
use Fullspec\AnswerEng\Model\Registry\RoleResolver;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\FakeSettings;

final class RemoteStoreRegistryTest extends DbTestCase
{
    private RemoteStoreRegistry $registry;
    private SyncStateRepository $state;
    private FakeSettings $settings;
    private RoleResolver $roles;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new RemoteStoreRegistry($this->db);
        $this->state = new SyncStateRepository($this->db);
        $this->settings = new FakeSettings();
        $this->roles = new RoleResolver($this->settings, $this->state, $this->registry);
        $this->now = new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('UTC'));
    }

    /** A view that has pushed `key` through engine.test as acct_a with its current key. */
    private function pushed(int $storeId, string $key): int
    {
        $s = $this->settings->put($storeId, ['storeKey' => $key]);
        $this->state->recordAccount($storeId, 'acct_a', $s->apiKeyHash());
        return $this->registry->recordPush('https://engine.test', 'acct_a', $key, $storeId, $this->now);
    }

    public function testPushRecordsOneIdentityPerConnectionAndKey(): void
    {
        $a = $this->pushed(1, 'shop.example.com');
        self::assertSame($a, $this->registry->recordPush('https://engine.test', 'acct_a', 'shop.example.com', 1, $this->now));
        self::assertNotSame($a, $this->registry->recordPush('https://engine.test', 'acct_b', 'shop.example.com', 1, $this->now));
        self::assertCount(2, $this->registry->forView(1));
        self::assertSame('active', $this->registry->get($a)?->remoteStatus);
    }

    public function testTwoViewsShareOneIdentity(): void
    {
        $a = $this->pushed(1, 'shop.example.com');
        $this->settings->put(1, ['enabled' => false, 'storeKey' => 'shop.example.com']);
        $b = $this->pushed(2, 'shop.example.com');
        self::assertSame($a, $b);
        self::assertSame([1, 2], $this->registry->viewsOf($a));
        self::assertSame([2], $this->roles->activeUsers($a));
    }

    public function testChangingTheKeyMakesTheOldStorePreviousImmediately(): void
    {
        $old = $this->pushed(1, 'old.example.com');
        self::assertTrue($this->roles->isCurrent($this->registry->get($old), 1));
        $this->settings->put(1, ['storeKey' => 'new.example.com']);
        self::assertFalse($this->roles->isCurrent($this->registry->get($old), 1));
        self::assertNull($this->roles->currentFor(1));
    }

    public function testANewApiKeyMeansNothingIsCurrentUntilTheAccountIsSeenAgain(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        $this->settings->put(1, ['storeKey' => 'shop.example.com', 'apiKey' => 'ae_rotated']);
        self::assertFalse($this->roles->isCurrent($this->registry->get($id), 1));
        $this->state->recordAccount(1, 'acct_a', $this->settings->forStore(1)->apiKeyHash());
        self::assertTrue($this->roles->isCurrent($this->registry->get($id), 1));
    }

    public function testStatusAndRepushAfterDeletion(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        $this->registry->setStatus($id, 'pending_deletion', $this->now->modify('+7 days'));
        self::assertSame('2026-10-14 12:00:00', $this->registry->get($id)?->purgeAfter?->format('Y-m-d H:i:s'));
        $this->registry->setStatus($id, 'deleted', null);
        $this->pushed(1, 'shop.example.com');
        self::assertSame(['active', null], [$this->registry->get($id)?->remoteStatus, $this->registry->get($id)?->purgeAfter]);
    }

    public function testForgetRemovesTheLinkAndTheIdentityWithItsLastLink(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        $this->pushed(2, 'shop.example.com');
        $this->registry->forget($id, 1);
        self::assertNotNull($this->registry->get($id));
        $this->registry->forget($id, 2);
        self::assertNull($this->registry->get($id));
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --testsuite Db --filter RemoteStoreRegistryTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the registry**

`Model/Registry/RemoteStore.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Registry;

final class RemoteStore
{
    public function __construct(
        public readonly int $id,
        public readonly string $engineUrl,
        public readonly string $accountId,
        public readonly string $storeKey,
        public readonly string $remoteStatus,
        public readonly ?\DateTimeImmutable $purgeAfter,
        public readonly ?\DateTimeImmutable $lastSyncedAt
    ) {
    }
}
```

`Model/Registry/RemoteStoreRegistry.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Registry;

use Fullspec\AnswerEng\Model\Db\SqlExecutor;
use Fullspec\AnswerEng\Model\Support\Ts;

/**
 * Spec §7.1: remote identities (engine URL + account + store key) are kept
 * separate from the views that pushed to them, so a key change, a
 * connection change or two views sharing a key never lose track of a store.
 */
final class RemoteStoreRegistry
{
    public function __construct(private readonly SqlExecutor $db)
    {
    }

    private function stores(): string
    {
        return $this->db->table('answereng_remote_store');
    }

    private function links(): string
    {
        return $this->db->table('answereng_remote_store_view');
    }

    public function recordPush(string $engineUrl, string $accountId, string $storeKey, int $storeId, \DateTimeImmutable $now): int
    {
        // purge_after is assigned before remote_status: it must read the old status.
        $this->db->execute(
            "INSERT INTO {$this->stores()} (engine_url, account_id, store_key, remote_status, first_synced_at)
             VALUES (?, ?, ?, 'active', ?)
             ON DUPLICATE KEY UPDATE
                 remote_store_id = LAST_INSERT_ID(remote_store_id),
                 purge_after = IF(remote_status = 'deleted', NULL, purge_after),
                 remote_status = IF(remote_status = 'deleted', 'active', remote_status)",
            [$engineUrl, $accountId, $storeKey, Ts::fmt($now)]
        );
        $id = $this->db->lastInsertId();
        $this->db->execute(
            "INSERT INTO {$this->links()} (remote_store_id, store_id, last_synced_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE last_synced_at = VALUES(last_synced_at)",
            [$id, $storeId, Ts::fmt($now)]
        );
        return $id;
    }

    /** @return list<RemoteStore> newest link first */
    public function forView(int $storeId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT s.*, l.last_synced_at FROM {$this->stores()} s
             JOIN {$this->links()} l ON l.remote_store_id = s.remote_store_id
             WHERE l.store_id = ? ORDER BY l.last_synced_at DESC, s.remote_store_id DESC",
            [$storeId]
        );
        return array_map([$this, 'row'], $rows);
    }

    public function get(int $id): ?RemoteStore
    {
        $row = $this->db->fetchRow(
            "SELECT s.*, NULL AS last_synced_at FROM {$this->stores()} s WHERE s.remote_store_id = ?",
            [$id]
        );
        return $row === null ? null : $this->row($row);
    }

    /** @return list<int> */
    public function viewsOf(int $id): array
    {
        return array_map(static fn (array $r) => (int) $r['store_id'], $this->db->fetchAll(
            "SELECT store_id FROM {$this->links()} WHERE remote_store_id = ? ORDER BY store_id",
            [$id]
        ));
    }

    public function setStatus(int $id, string $status, ?\DateTimeImmutable $purgeAfter): void
    {
        $this->db->execute(
            "UPDATE {$this->stores()} SET remote_status = ?, purge_after = ? WHERE remote_store_id = ?",
            [$status, $purgeAfter === null ? null : Ts::fmt($purgeAfter), $id]
        );
    }

    /** Local only: drops this view's link, and the identity with its last link. */
    public function forget(int $id, int $storeId): void
    {
        $this->db->execute("DELETE FROM {$this->links()} WHERE remote_store_id = ? AND store_id = ?", [$id, $storeId]);
        $this->db->execute(
            "DELETE FROM {$this->stores()} WHERE remote_store_id = ?
               AND NOT EXISTS (SELECT 1 FROM {$this->links()} WHERE remote_store_id = ?)",
            [$id, $id]
        );
    }

    /** @param array<string,mixed> $row */
    private function row(array $row): RemoteStore
    {
        return new RemoteStore(
            (int) $row['remote_store_id'],
            (string) $row['engine_url'],
            (string) $row['account_id'],
            (string) $row['store_key'],
            (string) $row['remote_status'],
            Ts::parse($row['purge_after'] === null ? null : (string) $row['purge_after']),
            Ts::parse($row['last_synced_at'] === null ? null : (string) $row['last_synced_at'])
        );
    }
}
```

`Model/Registry/RoleResolver.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Registry;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;

/**
 * Roles are derived, never stored (spec §7.1): an identity is CURRENT for a
 * view when it equals the view's effective target right now — enabled, same
 * store key, same engine URL, and the account last seen for the CURRENT key.
 */
final class RoleResolver
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly RemoteStoreRegistry $registry
    ) {
    }

    public function isCurrent(RemoteStore $r, int $storeId): bool
    {
        $s = $this->settings->forStore($storeId);
        $state = $this->state->get($storeId);
        return $s->enabled
            && $s->storeKey === $r->storeKey
            && $s->engineUrl === $r->engineUrl
            && $state->accountKeyHash === $s->apiKeyHash()
            && $state->accountId === $r->accountId;
    }

    public function currentFor(int $storeId): ?RemoteStore
    {
        foreach ($this->registry->forView($storeId) as $r) {
            if ($this->isCurrent($r, $storeId)) {
                return $r;
            }
        }
        return null;
    }

    /** @return list<int> views for which this identity is current */
    public function activeUsers(int $identityId): array
    {
        $identity = $this->registry->get($identityId);
        if ($identity === null) {
            return [];
        }
        return array_values(array_filter($this->registry->viewsOf($identityId),
            fn (int $storeId) => $this->isCurrent($identity, $storeId)));
    }
}
```

- [ ] **Step 4: Run, lint, analyse, commit**

Run: `vendor/bin/phpunit --testsuite Db --filter RemoteStoreRegistryTest`, `vendor/bin/phpcs`, `vendor/bin/phpstan analyse --no-progress` — PASS.

```bash
git add Model/Registry Test/Db/RemoteStoreRegistryTest.php
git commit -m "feat: remote store registry with derived current/previous roles" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: The syncer

**Files:**
- Create: `Model/Support/SyncLock.php`, `Model/Support/MagentoSyncLock.php`, `Model/Sync/SyncRunner.php`, `Model/Sync/SyncResult.php`, `Model/Sync/Syncer.php`
- Create: `Test/Support/FakeLock.php`
- Modify: `Test/Unit/Engine/FakeTransport.php` (add an `onSend` hook)
- Test: `Test/Db/SyncerTest.php`
- Modify: `etc/di.xml`

**Interfaces:**
- Consumes: everything from Tasks 2–9.
- Produces: `SyncLock::acquire(string $name): bool`, `::release(string $name): void`; `SyncRunner::sync(int $storeId, string $source, bool $manual = false): SyncResult`; `SyncResult` (readonly `status`, `message`, `until: ?\DateTimeImmutable`) with status constants `PUSHED, CLEARED, BLOCKED, PAUSED, RETRYING, EXHAUSTED, SKIPPED, THROTTLED, SUPERSEDED, FAILED`; `Syncer implements SyncRunner`.

- [ ] **Step 1: Add the send hook and the lock double**

In `Test/Unit/Engine/FakeTransport.php` add a public property and call it at the top of `send()`:

```php
    /** Called with each request before its response is returned (e.g. to change config mid-push). */
    public ?\Closure $onSend = null;
```

```php
        if ($this->onSend !== null) {
            ($this->onSend)($method, $url);
        }
```

(insert the second snippet as the first statement of `send()`, before the request is recorded).

`Test/Support/FakeLock.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Support;

use Fullspec\AnswerEng\Model\Support\SyncLock;

final class FakeLock implements SyncLock
{
    /** @var array<string,bool> */
    public array $held = [];

    public function acquire(string $name): bool
    {
        if (isset($this->held[$name])) {
            return false;
        }
        $this->held[$name] = true;
        return true;
    }

    public function release(string $name): void
    {
        unset($this->held[$name]);
    }
}
```

- [ ] **Step 2: Write the failing tests**

`Test/Db/SyncerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Catalog\CatalogBuilder;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Engine\TransportException;
use Fullspec\AnswerEng\Model\Registry\RemoteStoreRegistry;
use Fullspec\AnswerEng\Model\Sync\Classifier;
use Fullspec\AnswerEng\Model\Sync\SyncLog;
use Fullspec\AnswerEng\Model\Sync\SyncResult;
use Fullspec\AnswerEng\Model\Sync\Syncer;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\FakeCatalogSource;
use Fullspec\AnswerEng\Test\Support\FakeLock;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Fullspec\AnswerEng\Test\Support\FixedClock;
use Fullspec\AnswerEng\Test\Unit\Engine\FakeTransport;
use Psr\Log\NullLogger;

final class SyncerTest extends DbTestCase
{
    private const ACCT = 'acct_0123456789abcdef0123456789abcdef';
    private FakeSettings $settings;
    private FakeCatalogSource $catalog;
    private FakeTransport $transport;
    private FakeLock $lock;
    private FixedClock $clock;
    private SyncStateRepository $state;
    private SyncLog $log;
    private RemoteStoreRegistry $registry;
    private Syncer $syncer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new FakeSettings();
        $this->catalog = new FakeCatalogSource();
        $this->transport = new FakeTransport();
        $this->lock = new FakeLock();
        $this->clock = new FixedClock();
        $this->state = new SyncStateRepository($this->db);
        $this->log = new SyncLog($this->db);
        $this->registry = new RemoteStoreRegistry($this->db);
        $this->syncer = new Syncer($this->settings, $this->state, $this->log, new CatalogBuilder($this->catalog),
            new EngineClient($this->transport, new NullLogger()), new Classifier(), $this->registry,
            $this->lock, $this->clock, new NullLogger());
        $this->settings->put(1, ['storeKey' => 'shop.example.com']);
        $this->catalog->add(1, 10);
    }

    private function ok(int $status = 200, array $json = ['store_id' => 5]): void
    {
        $this->transport->enqueue(FakeTransport::json($status, $json, ['X-AnswerEng-Account' => self::ACCT]));
    }

    private function fail(int $status, array $json = []): void
    {
        $this->transport->enqueue(FakeTransport::json($status, $json, ['X-AnswerEng-Account' => self::ACCT]));
    }

    public function testPushRecordsSuccessAccountRegistryAndLog(): void
    {
        $this->state->markDirty([1], $this->clock->now()->modify('-1 hour'));
        $this->ok();
        $result = $this->syncer->sync(1, 'debounce');
        self::assertSame(SyncResult::PUSHED, $result->status);
        $req = $this->transport->requests[0];
        self::assertSame('https://engine.test/catalog', $req['url']);
        self::assertSame('shop.example.com', json_decode((string) $req['body'], true)['store_key']);
        $s = $this->state->get(1);
        self::assertSame(['ok', false, 1, 'shop.example.com', self::ACCT],
            [$s->state, $s->dirty, $s->productCount, $s->syncedStoreKey, $s->accountId]);
        self::assertSame('shop.example.com', $this->registry->forView(1)[0]->storeKey);
        self::assertSame('pushed', $this->log->recent(1)[0]['outcome']);
    }

    public function testNoApiKeyNeverContactsTheEngine(): void
    {
        $this->settings->put(1, ['apiKey' => '']);
        self::assertSame(SyncResult::BLOCKED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame([], $this->transport->requests);
        self::assertSame(['blocked', 'no_api_key'], [$this->state->get(1)->state, $this->state->get(1)->reason]);
    }

    public function testDisabledViewIsSkipped(): void
    {
        $this->settings->put(1, ['enabled' => false]);
        self::assertSame(SyncResult::SKIPPED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame([], $this->transport->requests);
    }

    public function testDuplicateKeyIsBlocked(): void
    {
        $this->settings->put(2, ['storeKey' => 'store1.example.com']);
        $this->settings->put(1, ['storeKey' => 'store1.example.com']);
        self::assertSame(SyncResult::BLOCKED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame('duplicate_store_key', $this->state->get(1)->reason);
        self::assertSame([], $this->transport->requests);
    }

    public function testTooManyProductsIsBlockedWithoutARequest(): void
    {
        for ($i = 100; $i <= 5100; $i++) {
            $this->catalog->add(1, $i);
        }
        self::assertSame(SyncResult::BLOCKED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame('too_many_products', $this->state->get(1)->reason);
        self::assertSame([], $this->transport->requests);
    }

    public function testEmptyCatalogNeverSyncedCallsNothing(): void
    {
        $this->catalog->products = [];
        self::assertSame(SyncResult::CLEARED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame([], $this->transport->requests);
        self::assertSame(['ok', 'catalog_cleared'], [$this->state->get(1)->state, $this->state->get(1)->reason]);
    }

    public function testEmptyCatalogAfterASyncClearsTheEngine(): void
    {
        $this->ok();
        $this->syncer->sync(1, 'debounce');
        $this->catalog->products = [];
        $this->fail(404, ['code' => 'unknown_store']);   // already clear is fine
        self::assertSame(SyncResult::CLEARED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame(['DELETE', 'https://engine.test/stores/shop.example.com/catalog'],
            [$this->transport->requests[1]['method'], $this->transport->requests[1]['url']]);
        self::assertSame([0, 'catalog_cleared'], [$this->state->get(1)->productCount, $this->state->get(1)->reason]);
    }

    public function testTransientFailuresRetryThenExhaust(): void
    {
        $this->fail(504);
        self::assertSame(SyncResult::RETRYING, $this->syncer->sync(1, 'debounce')->status);
        $this->transport->enqueue(new TransportException('reset'));
        $this->syncer->sync(1, 'debounce');
        $this->fail(502);
        self::assertSame(SyncResult::EXHAUSTED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame(['exhausted', 3], [$this->state->get(1)->state, $this->state->get(1)->attempts]);
    }

    public function testStoreLimitPausesWithItsOwnReason(): void
    {
        $this->fail(403, ['error' => 'plan allows 1 store(s)', 'code' => 'store_limit', 'limit' => 1]);
        self::assertSame(SyncResult::PAUSED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame(['paused', 'store_limit'], [$this->state->get(1)->state, $this->state->get(1)->reason]);
        self::assertSame('HTTP 403 store_limit limit=1', $this->log->recent(1)[0]['detail']);
    }

    public function testManualSyncHonoursAThrottleWindow(): void
    {
        $this->transport->enqueue(FakeTransport::json(429, ['code' => 'throttled'], ['Retry-After' => '600']));
        $this->syncer->sync(1, 'debounce');
        $result = $this->syncer->sync(1, 'manual', true);
        self::assertSame(SyncResult::THROTTLED, $result->status);
        self::assertSame('2026-10-07 12:10:00', $result->until?->format('Y-m-d H:i:s'));
        self::assertCount(1, $this->transport->requests);
    }

    public function testAConfigChangeDuringThePushDiscardsTheOutcomeButKeepsTheRemoteRecord(): void
    {
        $this->transport->onSend = function (): void {
            $this->state->applySettingsHash(1, 'changed-mid-push', $this->clock->now());
        };
        $this->ok();
        self::assertSame(SyncResult::SUPERSEDED, $this->syncer->sync(1, 'debounce')->status);
        self::assertNull($this->state->get(1)->syncedStoreKey);
        self::assertTrue($this->state->get(1)->dirty);
        self::assertCount(1, $this->registry->forView(1));   // remote side effect still recorded
    }

    public function testAHeldLockSkipsWithoutARequest(): void
    {
        $this->lock->held['answereng_sync_1'] = true;
        self::assertSame(SyncResult::SKIPPED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame([], $this->transport->requests);
    }

    public function testACatalogFailureIsTransientAndPushesNothing(): void
    {
        for ($i = 11; $i <= 600; $i++) {
            $this->catalog->add(1, $i);
        }
        $this->catalog->failOnChunk = new \RuntimeException('db gone');
        self::assertSame(SyncResult::FAILED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame([], $this->transport->requests);
        self::assertSame('retrying', $this->state->get(1)->state);
        self::assertSame([], $this->lock->held);
    }

    public function testSuccessWithoutAnAccountHeaderSkipsTheRegistry(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, ['store_id' => 1]));
        self::assertSame(SyncResult::PUSHED, $this->syncer->sync(1, 'debounce')->status);
        self::assertSame([], $this->registry->forView(1));
    }
}
```

- [ ] **Step 3: Run them to verify they fail**

Run: `vendor/bin/phpunit --testsuite Db --filter SyncerTest`
Expected: FAIL — classes not found.

- [ ] **Step 4: Write the lock, runner interface and result**

`Model/Support/SyncLock.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Support;

interface SyncLock
{
    /** Non-blocking: false when another process holds it. */
    public function acquire(string $name): bool;

    public function release(string $name): void;
}
```

`Model/Support/MagentoSyncLock.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Support;

use Magento\Framework\Lock\LockManagerInterface;

final class MagentoSyncLock implements SyncLock
{
    public function __construct(private readonly LockManagerInterface $locks)
    {
    }

    public function acquire(string $name): bool
    {
        return $this->locks->lock($name, 0);
    }

    public function release(string $name): void
    {
        $this->locks->unlock($name);
    }
}
```

`Model/Sync/SyncRunner.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

interface SyncRunner
{
    /** $manual = an explicit merchant action (Sync now / CLI), which bypasses cron selection. */
    public function sync(int $storeId, string $source, bool $manual = false): SyncResult;
}
```

`Model/Sync/SyncResult.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

final class SyncResult
{
    public const PUSHED = 'pushed';
    public const CLEARED = 'cleared';
    public const BLOCKED = 'blocked';
    public const PAUSED = 'paused';
    public const RETRYING = 'retrying';
    public const EXHAUSTED = 'exhausted';
    public const SKIPPED = 'skipped';
    public const THROTTLED = 'throttled';
    public const SUPERSEDED = 'superseded';
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?\DateTimeImmutable $until = null
    ) {
    }

    public function isError(): bool
    {
        return in_array($this->status,
            [self::BLOCKED, self::PAUSED, self::RETRYING, self::EXHAUSTED, self::THROTTLED, self::FAILED], true);
    }
}
```

- [ ] **Step 5: Write the syncer**

`Model/Sync/Syncer.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Catalog\CatalogBuilder;
use Fullspec\AnswerEng\Model\Catalog\ExportResult;
use Fullspec\AnswerEng\Model\Config\Settings;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Engine\EngineResult;
use Fullspec\AnswerEng\Model\Registry\RemoteStoreRegistry;
use Fullspec\AnswerEng\Model\Support\Clock;
use Fullspec\AnswerEng\Model\Support\SyncLock;
use Psr\Log\LoggerInterface;

/**
 * One sync of one store view (spec §6): lock → revision + settings snapshot
 * → guards → export → push (or clear) → classify → one revision-guarded
 * state update. A successful remote side effect is recorded in the registry
 * unconditionally, even when the state update is superseded.
 */
final class Syncer implements SyncRunner
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly SyncLog $log,
        private readonly CatalogBuilder $builder,
        private readonly EngineClient $engine,
        private readonly Classifier $classifier,
        private readonly RemoteStoreRegistry $registry,
        private readonly SyncLock $lock,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    public function sync(int $storeId, string $source, bool $manual = false): SyncResult
    {
        $lockName = 'answereng_sync_' . $storeId;
        if (!$this->lock->acquire($lockName)) {
            $this->log->append($storeId, $this->clock->now(), $source, 'skipped', 'another sync is running');
            return new SyncResult(SyncResult::SKIPPED, (string) __('Another sync for this store view is running.'));
        }
        try {
            return $this->run($storeId, $source, $manual);
        } finally {
            $this->lock->release($lockName);
        }
    }

    private function run(int $storeId, string $source, bool $manual): SyncResult
    {
        $revision = $this->state->get($storeId)->configRevision;
        $s = $this->settings->forStore($storeId);
        if (!$s->enabled) {
            return new SyncResult(SyncResult::SKIPPED, (string) __('This store view is not enabled for AnswerEng.'));
        }
        foreach ([['no_api_key', $s->apiKey === ''], ['no_brand_names', $s->brandNames === []],
                     ['no_store_key', $s->storeKey === ''], ['duplicate_store_key', $this->isDuplicate($s)]] as [$reason, $hit]) {
            if ($hit) {
                return $this->blocked($storeId, $revision, $source, $reason);
            }
        }
        $now = $this->clock->now();
        if ($manual && ($until = $this->state->throttledUntil($storeId, $now)) !== null) {
            return new SyncResult(SyncResult::THROTTLED, (string) __('Rate limited by AnswerEng — try again after %1 UTC.',
                $until->format('Y-m-d H:i')), $until);
        }

        $startedAt = $now;
        try {
            $export = $this->builder->build($s);
        } catch (\Throwable $e) {
            $this->logger->error('AnswerEng catalog export failed', ['store_id' => $storeId,
                'exception' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)]);
            $this->state->recordTransient($storeId, $revision, $this->clock->now(), null);
            $this->log->append($storeId, $this->clock->now(), $source, 'error', 'catalog export failed: ' . get_class($e));
            return new SyncResult(SyncResult::FAILED, (string) __('Reading the catalog failed; nothing was sent. It will be retried.'));
        }

        if ($export->kind === ExportResult::TOO_MANY) {
            return $this->blocked($storeId, $revision, $source, 'too_many_products', "{$export->count} eligible products");
        }
        if ($export->kind === ExportResult::EMPTY) {
            return $this->clearEmpty($s, $revision, $startedAt, $source);
        }

        $result = $this->engine->postCatalog($s->connection(), $export->json);
        $this->afterEngineCall($s, $result, true);
        return $this->apply($s, $revision, $startedAt, $this->classifier->classify($result), $export->count, $source, null);
    }

    private function clearEmpty(Settings $s, int $revision, \DateTimeImmutable $startedAt, string $source): SyncResult
    {
        if ($this->state->get($s->storeId)->syncedStoreKey === null) {
            return $this->apply($s, $revision, $startedAt,
                new Outcome(OutcomeKind::AlreadyClear, null, 'never synced'), 0, $source, 'catalog_cleared');
        }
        $result = $this->engine->clearCatalog($s->connection(), $s->storeKey);
        $this->afterEngineCall($s, $result, false);
        return $this->apply($s, $revision, $startedAt, $this->classifier->classify($result, true), 0, $source, 'catalog_cleared');
    }

    private function afterEngineCall(Settings $s, EngineResult $result, bool $isPush): void
    {
        if ($result->account === null) {
            if ($isPush && $result->isSuccess()) {
                $this->logger->warning('AnswerEng push succeeded without an account header', ['store_id' => $s->storeId]);
            }
            return;
        }
        $this->state->recordAccount($s->storeId, $result->account, $s->apiKeyHash());
        if ($isPush && $result->isSuccess()) {
            $this->registry->recordPush($s->engineUrl, $result->account, $s->storeKey, $s->storeId, $this->clock->now());
        }
    }

    private function apply(
        Settings $s,
        int $revision,
        \DateTimeImmutable $startedAt,
        Outcome $o,
        int $count,
        string $source,
        ?string $successReason
    ): SyncResult {
        $now = $this->clock->now();
        $id = $s->storeId;
        switch ($o->kind) {
            case OutcomeKind::Success:
            case OutcomeKind::AlreadyClear:
                $applied = $this->state->recordSuccess($id, $revision, $startedAt, $now, $count,
                    $successReason === null ? $s->storeKey : null, $successReason);
                $result = $successReason === null
                    ? new SyncResult(SyncResult::PUSHED, (string) __('Sent %1 products to AnswerEng.', $count))
                    : new SyncResult(SyncResult::CLEARED, (string) __('No eligible products — the AnswerEng catalog is '
                        . 'cleared and weekly analysis is paused until products return.'));
                break;
            case OutcomeKind::Transient:
            case OutcomeKind::Throttled:
                $applied = $this->state->recordTransient($id, $revision, $now,
                    $o->kind === OutcomeKind::Throttled ? $o->retryAfterSeconds : null);
                $exhausted = $this->state->get($id)->state === SyncStateRepository::EXHAUSTED;
                $result = $exhausted
                    ? new SyncResult(SyncResult::EXHAUSTED, (string) __('AnswerEng could not be reached after 3 attempts. '
                        . 'The nightly sync will try again.'))
                    : new SyncResult(SyncResult::RETRYING, $o->kind === OutcomeKind::Throttled
                        ? (string) __('Rate limited, retrying.')
                        : (string) __('AnswerEng is waking up or unreachable; retrying shortly.'));
                break;
            default:
                $applied = $this->state->recordPaused($id, $revision, (string) $o->reason);
                $result = new SyncResult(SyncResult::PAUSED, (string) __('AnswerEng refused the request (%1).', $o->detail));
        }
        if (!$applied) {
            $this->log->append($id, $now, $source, 'superseded', 'superseded by a configuration change');
            return new SyncResult(SyncResult::SUPERSEDED, (string) __('The configuration changed during the sync; '
                . 'the new settings will sync shortly.'));
        }
        $this->log->append($id, $now, $source, $result->status, $o->detail);
        return $result;
    }

    private function blocked(int $storeId, int $revision, string $source, string $reason, ?string $detail = null): SyncResult
    {
        $this->state->recordBlocked($storeId, $revision, $reason);
        $this->log->append($storeId, $this->clock->now(), $source, 'blocked', $detail ?? $reason);
        return new SyncResult(SyncResult::BLOCKED, (string) __('Not synced: %1.', $reason));
    }

    private function isDuplicate(Settings $s): bool
    {
        foreach ($this->settings->enabledStoreIds() as $other) {
            if ($other !== $s->storeId && $this->settings->forStore($other)->storeKey === $s->storeKey) {
                return true;
            }
        }
        return false;
    }
}
```

`__()` (used for merchant-facing messages) is available in tests and PHPStan through the `Test/bootstrap.php` shim from Task 1.

Add to `etc/di.xml`:

```xml
    <preference for="Fullspec\AnswerEng\Model\Support\SyncLock"
                type="Fullspec\AnswerEng\Model\Support\MagentoSyncLock"/>
    <preference for="Fullspec\AnswerEng\Model\Sync\SyncRunner"
                type="Fullspec\AnswerEng\Model\Sync\Syncer"/>
    <type name="Fullspec\AnswerEng\Model\Sync\Syncer">
        <arguments>
            <argument name="logger" xsi:type="object">Fullspec\AnswerEng\Logger</argument>
        </arguments>
    </type>
```

- [ ] **Step 6: Run, lint, analyse, commit**

Run: `vendor/bin/phpunit` (both suites, with the Db env), `vendor/bin/phpcs`, `vendor/bin/phpstan analyse --no-progress` — PASS.

```bash
git add Model/Support/SyncLock.php Model/Support/MagentoSyncLock.php Model/Sync/SyncRunner.php Model/Sync/SyncResult.php Model/Sync/Syncer.php Test/Support/FakeLock.php Test/Unit/Engine/FakeTransport.php Test/Db/SyncerTest.php etc/di.xml
git commit -m "feat: per-view syncer with guards, empty-catalog clear and revision-guarded outcomes" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Change detection and configuration-change handling

**Files:**
- Create: `Model/Sync/ChangeTracker.php`, `Model/Sync/ConfigChangeHandler.php`
- Create: `Observer/ProductSaveBefore.php`, `Observer/ProductSaveAfter.php`, `Observer/ProductDeleteBefore.php`, `Observer/ProductDeleteAfter.php`, `Observer/MarkAllDirty.php`, `Observer/ConfigSaved.php`
- Create: `etc/events.xml`, `etc/adminhtml/events.xml`
- Test: `Test/Db/ChangeTrackingTest.php`, `Test/Unit/Observer/ProductSaveAfterTest.php`

**Interfaces:**
- Consumes: `SettingsProvider`, `SyncStateRepository`, `Clock`.
- Produces: `ChangeTracker::markWebsites(list<int> $websiteIds): void`, `::markAll(): void`; `ConfigChangeHandler::handle(): list<int>` (store ids whose revision was bumped); product data key `ProductSaveBefore::PREVIOUS_WEBSITES = '_answereng_previous_website_ids'`.

- [ ] **Step 1: Write the failing tests**

`Test/Db/ChangeTrackingTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Sync\ChangeTracker;
use Fullspec\AnswerEng\Model\Sync\ConfigChangeHandler;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Fullspec\AnswerEng\Test\Support\FixedClock;

final class ChangeTrackingTest extends DbTestCase
{
    public function testMarksOnlyEnabledViewsOfTheGivenWebsites(): void
    {
        $settings = new FakeSettings();
        $settings->put(1, [], 1);
        $settings->put(2, [], 2);
        $settings->put(3, ['enabled' => false], 1);
        $state = new SyncStateRepository($this->db);
        $tracker = new ChangeTracker($settings, $state, new FixedClock());
        $tracker->markWebsites([1]);
        self::assertSame([true, false, false], [$state->get(1)->dirty, $state->get(2)->dirty, $state->get(3)->dirty]);
        $tracker->markAll();
        self::assertTrue($state->get(2)->dirty);
        self::assertFalse($state->get(3)->dirty);
    }

    public function testConfigChangesBumpExactlyTheViewsWhoseEffectiveSettingsChanged(): void
    {
        $settings = new FakeSettings();
        $settings->put(1);
        $settings->put(2);
        $state = new SyncStateRepository($this->db);
        $handler = new ConfigChangeHandler($settings, $state, new FixedClock());
        self::assertSame([1, 2], $handler->handle());       // first save records hashes
        self::assertSame([], $handler->handle());           // nothing changed
        $settings->put(2, ['brands' => ['Acme', 'New Brand']]);
        self::assertSame([2], $handler->handle());
        self::assertSame([2, 3], [$state->get(1)->configRevision, $state->get(2)->configRevision]);
    }
}
```

`Test/Unit/Observer/ProductSaveAfterTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Observer;

use Fullspec\AnswerEng\Observer\ProductSaveAfter;
use Fullspec\AnswerEng\Observer\ProductSaveBefore;
use Fullspec\AnswerEng\Model\Sync\ChangeTracker;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

final class ProductSaveAfterTest extends TestCase
{
    public function testMarksTheUnionOfPreviousAndCurrentWebsites(): void
    {
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()
            ->onlyMethods(['getWebsiteIds', 'getData'])->getMock();
        $product->method('getWebsiteIds')->willReturn([2, 3]);
        $product->method('getData')->with(ProductSaveBefore::PREVIOUS_WEBSITES)->willReturn([1, 2]);
        $event = new Event(['product' => $product]);
        $tracker = $this->createMock(ChangeTracker::class);
        $tracker->expects(self::once())->method('markWebsites')->with([1, 2, 3]);
        (new ProductSaveAfter($tracker))->execute(new Observer(['event' => $event]));
    }
}
```

Because `ChangeTracker` must be mockable here, declare it `class` (not `final`).

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter 'ChangeTracking|ProductSaveAfter'`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the tracker and the config-change handler**

`Model/Sync/ChangeTracker.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Support\Clock;

/** Marks enabled views dirty; the 5-minute cron pushes them once they go quiet. */
class ChangeTracker
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly Clock $clock
    ) {
    }

    /** @param list<int> $websiteIds */
    public function markWebsites(array $websiteIds): void
    {
        $ids = $this->settings->storeIdsForWebsites(array_values(array_unique(array_map('intval', $websiteIds))));
        $this->state->markDirty($ids, $this->clock->now());
    }

    public function markAll(): void
    {
        $this->state->markDirty($this->settings->enabledStoreIds(), $this->clock->now());
    }
}
```

`Model/Sync/ConfigChangeHandler.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Support\Clock;

/**
 * Spec §6 "Configuration saves": compares each view's EFFECTIVE settings
 * hash, so a default- or website-scope change bumps exactly the views it
 * affects. A bump marks the view dirty, resets retries and pauses, and
 * makes any in-flight push's outcome superseded.
 */
class ConfigChangeHandler
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly Clock $clock
    ) {
    }

    /** @return list<int> */
    public function handle(): array
    {
        $bumped = [];
        foreach ($this->settings->allStoreIds() as $storeId) {
            if ($this->state->applySettingsHash($storeId, $this->settings->forStore($storeId)->hash(), $this->clock->now())) {
                $bumped[] = $storeId;
            }
        }
        return $bumped;
    }
}
```

- [ ] **Step 4: Write the observers and event wiring**

`Observer/ProductSaveBefore.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/** Stash the PERSISTED website ids so a website that loses the product is resynced too. */
class ProductSaveBefore implements ObserverInterface
{
    public const PREVIOUS_WEBSITES = '_answereng_previous_website_ids';

    public function execute(Observer $observer): void
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $observer->getEvent()->getData('product');
        $previous = $product->getId() ? $product->getResource()->getWebsiteIds($product) : [];
        $product->setData(self::PREVIOUS_WEBSITES, array_map('intval', (array) $previous));
    }
}
```

`Observer/ProductSaveAfter.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Fullspec\AnswerEng\Model\Sync\ChangeTracker;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class ProductSaveAfter implements ObserverInterface
{
    public function __construct(private readonly ChangeTracker $tracker)
    {
    }

    public function execute(Observer $observer): void
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $observer->getEvent()->getData('product');
        $ids = array_merge(
            (array) $product->getData(ProductSaveBefore::PREVIOUS_WEBSITES),
            (array) $product->getWebsiteIds()
        );
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $this->tracker->markWebsites($ids);
    }
}
```

`Observer/ProductDeleteBefore.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/** The product-website links are gone after delete; capture them first. */
class ProductDeleteBefore implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $observer->getEvent()->getData('product');
        $product->setData(ProductSaveBefore::PREVIOUS_WEBSITES,
            array_map('intval', (array) $product->getResource()->getWebsiteIds($product)));
    }
}
```

`Observer/ProductDeleteAfter.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Fullspec\AnswerEng\Model\Sync\ChangeTracker;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class ProductDeleteAfter implements ObserverInterface
{
    public function __construct(private readonly ChangeTracker $tracker)
    {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');
        $this->tracker->markWebsites((array) $product->getData(ProductSaveBefore::PREVIOUS_WEBSITES));
    }
}
```

`Observer/MarkAllDirty.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Fullspec\AnswerEng\Model\Sync\ChangeTracker;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/** Bulk changes (mass attribute/website updates, imports, category saves): mark every enabled view. */
class MarkAllDirty implements ObserverInterface
{
    public function __construct(private readonly ChangeTracker $tracker)
    {
    }

    public function execute(Observer $observer): void
    {
        $this->tracker->markAll();
    }
}
```

`Observer/ConfigSaved.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Fullspec\AnswerEng\Model\Sync\ConfigChangeHandler;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class ConfigSaved implements ObserverInterface
{
    public function __construct(private readonly ConfigChangeHandler $handler)
    {
    }

    public function execute(Observer $observer): void
    {
        $this->handler->handle();
    }
}
```

`etc/events.xml`:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Event/etc/events.xsd">
    <event name="catalog_product_save_before">
        <observer name="answereng_previous_websites" instance="Fullspec\AnswerEng\Observer\ProductSaveBefore"/>
    </event>
    <event name="catalog_product_save_after">
        <observer name="answereng_mark_dirty" instance="Fullspec\AnswerEng\Observer\ProductSaveAfter"/>
    </event>
    <event name="catalog_product_delete_before">
        <observer name="answereng_previous_websites" instance="Fullspec\AnswerEng\Observer\ProductDeleteBefore"/>
    </event>
    <event name="catalog_product_delete_after">
        <observer name="answereng_mark_dirty" instance="Fullspec\AnswerEng\Observer\ProductDeleteAfter"/>
    </event>
    <event name="catalog_product_attribute_update_before">
        <observer name="answereng_mark_all" instance="Fullspec\AnswerEng\Observer\MarkAllDirty"/>
    </event>
    <event name="catalog_product_to_website_change">
        <observer name="answereng_mark_all" instance="Fullspec\AnswerEng\Observer\MarkAllDirty"/>
    </event>
    <event name="catalog_product_import_bunch_save_after">
        <observer name="answereng_mark_all" instance="Fullspec\AnswerEng\Observer\MarkAllDirty"/>
    </event>
    <event name="catalog_category_save_after">
        <observer name="answereng_mark_all" instance="Fullspec\AnswerEng\Observer\MarkAllDirty"/>
    </event>
</config>
```

`etc/adminhtml/events.xml`:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Event/etc/events.xsd">
    <event name="admin_system_config_changed_section_answereng">
        <observer name="answereng_config_saved" instance="Fullspec\AnswerEng\Observer\ConfigSaved"/>
    </event>
</config>
```

- [ ] **Step 5: Run, lint, analyse, commit**

Run: `vendor/bin/phpunit`, `vendor/bin/phpcs`, `vendor/bin/phpstan analyse --no-progress` — PASS.

```bash
git add Model/Sync/ChangeTracker.php Model/Sync/ConfigChangeHandler.php Observer etc/events.xml etc/adminhtml/events.xml Test/Db/ChangeTrackingTest.php Test/Unit/Observer
git commit -m "feat: catalog change detection and config-save revision bumps" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Cron jobs and CLI command

**Files:**
- Create: `Cron/SyncDirty.php`, `Cron/Reconcile.php`, `Console/Command/Sync.php`, `etc/crontab.xml`
- Create: `Test/Support/FakeRunner.php`
- Test: `Test/Db/CronTest.php`, `Test/Unit/Console/SyncCommandTest.php`
- Modify: `etc/di.xml` (command registration)

**Interfaces:**
- Consumes: `SyncRunner` (Task 10), `SyncStateRepository`, `SettingsProvider`, `Clock`.
- Produces: `SyncDirty::execute(): void` (source `debounce`), `Reconcile::execute(): void` (source `reconcile`), CLI `answereng:sync [--store=<code>]` (source `cli`, manual).

- [ ] **Step 1: Write the runner double and failing tests**

`Test/Support/FakeRunner.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Support;

use Fullspec\AnswerEng\Model\Sync\SyncResult;
use Fullspec\AnswerEng\Model\Sync\SyncRunner;

final class FakeRunner implements SyncRunner
{
    /** @var list<array{int,string,bool}> */
    public array $calls = [];
    public SyncResult $result;

    public function __construct()
    {
        $this->result = new SyncResult(SyncResult::PUSHED, 'Sent 1 products to AnswerEng.');
    }

    public function sync(int $storeId, string $source, bool $manual = false): SyncResult
    {
        $this->calls[] = [$storeId, $source, $manual];
        return $this->result;
    }
}
```

`Test/Db/CronTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Cron\Reconcile;
use Fullspec\AnswerEng\Cron\SyncDirty;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\FakeRunner;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Fullspec\AnswerEng\Test\Support\FixedClock;

final class CronTest extends DbTestCase
{
    public function testDebounceSyncsOnlyQuietDirtyEnabledViews(): void
    {
        $settings = new FakeSettings();
        $settings->put(1);
        $settings->put(2);
        $settings->put(3, ['enabled' => false]);
        $clock = new FixedClock();
        $state = new SyncStateRepository($this->db);
        $state->markDirty([1, 3], $clock->now()->modify('-10 minutes'));
        $state->markDirty([2], $clock->now()->modify('-1 minute'));
        $runner = new FakeRunner();
        (new SyncDirty($settings, $state, $runner, $clock))->execute();
        self::assertSame([[1, 'debounce', false]], $runner->calls);
    }

    public function testReconcileSyncsEveryEligibleEnabledView(): void
    {
        $settings = new FakeSettings();
        $settings->put(1);
        $settings->put(2);
        $state = new SyncStateRepository($this->db);
        $state->ensure(2);
        $rev = $state->get(2)->configRevision;
        $state->recordPaused(2, $rev, 'invalid_key');
        $runner = new FakeRunner();
        (new Reconcile($settings, $state, $runner, new FixedClock()))->execute();
        self::assertSame([[1, 'reconcile', false]], $runner->calls);
    }
}
```

`Test/Unit/Console/SyncCommandTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Console;

use Fullspec\AnswerEng\Console\Command\Sync;
use Fullspec\AnswerEng\Model\Sync\SyncResult;
use Fullspec\AnswerEng\Test\Support\FakeRunner;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncCommandTest extends TestCase
{
    private FakeSettings $settings;
    private FakeRunner $runner;

    protected function setUp(): void
    {
        $this->settings = new FakeSettings();
        $this->settings->put(1);
        $this->settings->put(2);
        $this->settings->put(3, ['enabled' => false]);
        $this->runner = new FakeRunner();
    }

    private function run(array $input): CommandTester
    {
        $tester = new CommandTester(new Sync($this->settings, $this->runner));
        $tester->execute($input);
        return $tester;
    }

    public function testSyncsEveryEnabledViewByDefault(): void
    {
        $t = $this->run([]);
        self::assertSame(Command::SUCCESS, $t->getStatusCode());
        self::assertSame([[1, 'cli', true], [2, 'cli', true]], $this->runner->calls);
    }

    public function testSyncsOneViewByCode(): void
    {
        $this->run(['--store' => 'store2']);
        self::assertSame([[2, 'cli', true]], $this->runner->calls);
    }

    public function testUnknownOrDisabledViewFails(): void
    {
        self::assertSame(Command::FAILURE, $this->run(['--store' => 'nope'])->getStatusCode());
        self::assertSame(Command::FAILURE, $this->run(['--store' => 'store3'])->getStatusCode());
        self::assertSame([], $this->runner->calls);
    }

    public function testAnErrorResultFailsTheCommand(): void
    {
        $this->runner->result = new SyncResult(SyncResult::THROTTLED, 'Rate limited by AnswerEng — try again after 2026-10-07 12:10 UTC.');
        $t = $this->run(['--store' => 'store1']);
        self::assertSame(Command::FAILURE, $t->getStatusCode());
        self::assertStringContainsString('Rate limited', $t->getDisplay());
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter 'CronTest|SyncCommandTest'`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the cron jobs, command and wiring**

`Cron/SyncDirty.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Cron;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Support\Clock;
use Fullspec\AnswerEng\Model\Sync\SyncRunner;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;

/** Every 5 minutes: views dirty and quiet for 5 minutes, plus due retries (spec §6). */
class SyncDirty
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly SyncRunner $runner,
        private readonly Clock $clock
    ) {
    }

    public function execute(): void
    {
        foreach ($this->state->selectForDebounce($this->settings->enabledStoreIds(), $this->clock->now()) as $storeId) {
            $this->runner->sync($storeId, 'debounce');
        }
    }
}
```

`Cron/Reconcile.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Cron;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Support\Clock;
use Fullspec\AnswerEng\Model\Sync\SyncRunner;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;

/** Nightly full push of every enabled, non-paused view whose retry (if any) is due. */
class Reconcile
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly SyncRunner $runner,
        private readonly Clock $clock
    ) {
    }

    public function execute(): void
    {
        foreach ($this->state->selectForReconcile($this->settings->enabledStoreIds(), $this->clock->now()) as $storeId) {
            $this->runner->sync($storeId, 'reconcile');
        }
    }
}
```

`Console/Command/Sync.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Console\Command;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Sync\SyncRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Sync extends Command
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncRunner $runner
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('answereng:sync')
            ->setDescription('Push the catalog of AnswerEng-enabled store views now.')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view code (default: every enabled view)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $code = $input->getOption('store');
        $enabled = $this->settings->enabledStoreIds();
        if ($code !== null) {
            $id = $this->settings->storeIdByCode((string) $code);
            if ($id === null || !in_array($id, $enabled, true)) {
                $output->writeln("<error>Store view \"$code\" does not exist or is not enabled for AnswerEng.</error>");
                return Command::FAILURE;
            }
            $enabled = [$id];
        }
        $status = Command::SUCCESS;
        foreach ($enabled as $storeId) {
            $result = $this->runner->sync($storeId, 'cli', true);
            $output->writeln(sprintf('%s: %s', $this->settings->storeName($storeId), $result->message));
            if ($result->isError()) {
                $status = Command::FAILURE;
            }
        }
        return $status;
    }
}
```

`etc/crontab.xml`:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Cron:etc/crontab.xsd">
    <group id="default">
        <job name="answereng_sync_dirty" instance="Fullspec\AnswerEng\Cron\SyncDirty" method="execute">
            <schedule>*/5 * * * *</schedule>
        </job>
        <job name="answereng_reconcile" instance="Fullspec\AnswerEng\Cron\Reconcile" method="execute">
            <schedule>17 3 * * *</schedule>
        </job>
    </group>
</config>
```

Add to `etc/di.xml`:

```xml
    <type name="Magento\Framework\Console\CommandListInterface">
        <arguments>
            <argument name="commands" xsi:type="array">
                <item name="answereng_sync" xsi:type="object">Fullspec\AnswerEng\Console\Command\Sync</item>
            </argument>
        </arguments>
    </type>
```

- [ ] **Step 4: Run, lint, analyse, commit**

Run: `vendor/bin/phpunit`, `vendor/bin/phpcs`, `vendor/bin/phpstan analyse --no-progress` — PASS.

```bash
git add Cron Console etc/crontab.xml etc/di.xml Test/Support/FakeRunner.php Test/Db/CronTest.php Test/Unit/Console
git commit -m "feat: debounce and nightly reconcile cron jobs plus answereng:sync command" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Admin services — test connection, clear, delete, cancel, forget, summary

**Files:**
- Create: `Model/Admin/KeyValueCache.php`, `Model/Admin/MagentoKeyValueCache.php`, `Model/Config/ConfigDisabler.php`, `Model/Config/MagentoConfigDisabler.php`
- Create: `Model/Admin/ActionResult.php`, `Model/Admin/ConnectionReport.php`, `Model/Admin/RemoteStoreActions.php`, `Model/Admin/SummaryView.php`, `Model/Admin/SummaryProvider.php`
- Create: `Test/Support/ArrayCache.php`, `Test/Support/FakeDisabler.php`
- Test: `Test/Db/RemoteStoreActionsTest.php`, `Test/Db/SummaryProviderTest.php`
- Modify: `etc/di.xml`

**Interfaces:**
- Consumes: Tasks 2–11.
- Produces:
  - `KeyValueCache::load(string $key): ?string`, `::save(string $key, string $value, int $ttlSeconds): void`.
  - `ConfigDisabler::disable(int $storeId): void`.
  - `ActionResult(bool $ok, string $message)`.
  - `ConnectionReport(string $status, string $message, array $existingKeys = [])` with statuses `NO_KEY, UNREACHABLE, REJECTED, FOUND, WAITING_FIRST_RUN, FIRST_SYNC_NEEDED`.
  - `RemoteStoreActions`: `testConnection(int $storeId): ConnectionReport`, `clear(int $storeId, string $adminUser): ActionResult`, `delete(int $storeId, int $identityId, string $typedKey, bool $acknowledged, string $adminUser): ActionResult`, `cancel(int $storeId, int $identityId, string $adminUser): ActionResult`, `forget(int $storeId, int $identityId, string $adminUser): ActionResult`, `refreshStatuses(int $storeId): void`.
  - `SummaryView` (readonly `state, rate, low, high, runStartedAt, fetchedAt, dashboardUrl`) with states `DISABLED, FRESH, STALE, UNAVAILABLE, NOT_SYNCED, NO_RUN, PENDING_DELETION`.
  - `SummaryProvider::CACHE_SECONDS = 900`, `::DASHBOARD = 'https://app.answereng.io/stores/'`, `forView(int $storeId): SummaryView`.

- [ ] **Step 1: Write the doubles and failing tests**

`Test/Support/ArrayCache.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Support;

use Fullspec\AnswerEng\Model\Admin\KeyValueCache;

final class ArrayCache implements KeyValueCache
{
    /** @var array<string,string> */
    public array $items = [];

    public function load(string $key): ?string
    {
        return $this->items[$key] ?? null;
    }

    public function save(string $key, string $value, int $ttlSeconds): void
    {
        $this->items[$key] = $value;
    }
}
```

`Test/Support/FakeDisabler.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Support;

use Fullspec\AnswerEng\Model\Config\ConfigDisabler;
use Fullspec\AnswerEng\Model\Config\Settings;

final class FakeDisabler implements ConfigDisabler
{
    /** @var list<int> */
    public array $disabled = [];

    public function __construct(private readonly FakeSettings $settings)
    {
    }

    public function disable(int $storeId): void
    {
        $s = $this->settings->forStore($storeId);
        $this->settings->stores[$storeId] = new Settings($storeId, false, $s->engineUrl, $s->apiKey, $s->storeKey,
            $s->brandNames, $s->competitors);
        $this->disabled[] = $storeId;
    }
}
```

`Test/Db/RemoteStoreActionsTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Admin\ConnectionReport;
use Fullspec\AnswerEng\Model\Admin\RemoteStoreActions;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Registry\RemoteStoreRegistry;
use Fullspec\AnswerEng\Model\Registry\RoleResolver;
use Fullspec\AnswerEng\Model\Sync\Classifier;
use Fullspec\AnswerEng\Model\Sync\SyncLog;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\FakeDisabler;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Fullspec\AnswerEng\Test\Support\FixedClock;
use Fullspec\AnswerEng\Test\Unit\Engine\FakeTransport;
use Psr\Log\NullLogger;

final class RemoteStoreActionsTest extends DbTestCase
{
    private const A = 'acct_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const B = 'acct_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private FakeSettings $settings;
    private FakeTransport $transport;
    private SyncStateRepository $state;
    private RemoteStoreRegistry $registry;
    private FakeDisabler $disabler;
    private SyncLog $log;
    private RemoteStoreActions $actions;
    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new FakeSettings();
        $this->transport = new FakeTransport();
        $this->state = new SyncStateRepository($this->db);
        $this->registry = new RemoteStoreRegistry($this->db);
        $this->disabler = new FakeDisabler($this->settings);
        $this->log = new SyncLog($this->db);
        $this->clock = new FixedClock();
        $this->actions = new RemoteStoreActions($this->settings, $this->state, $this->log,
            new EngineClient($this->transport, new NullLogger()), new Classifier(), $this->registry,
            new RoleResolver($this->settings, $this->state, $this->registry), $this->disabler, $this->clock);
    }

    private function pushed(int $storeId, string $key, string $account = self::A): int
    {
        $s = $this->settings->put($storeId, ['storeKey' => $key]);
        $this->state->recordAccount($storeId, $account, $s->apiKeyHash());
        return $this->registry->recordPush($s->engineUrl, $account, $key, $storeId, $this->clock->now());
    }

    private function respond(int $status, ?array $json, string $account = self::A, array $headers = []): void
    {
        $this->transport->enqueue(FakeTransport::json($status, $json, ['X-AnswerEng-Account' => $account] + $headers));
    }

    private function methods(): array
    {
        return array_map(static fn ($r) => $r['method'] . ' ' . $r['url'], $this->transport->requests);
    }

    public function testConnectionWithoutAKeySendsNothing(): void
    {
        $this->settings->put(1, ['apiKey' => '']);
        self::assertSame(ConnectionReport::NO_KEY, $this->actions->testConnection(1)->status);
        self::assertSame([], $this->transport->requests);
    }

    public function testConnectionOutcomes(): void
    {
        $this->settings->put(1, ['storeKey' => 'shop.example.com']);
        $this->respond(504, null);
        $this->respond(504, null);
        self::assertSame(ConnectionReport::UNREACHABLE, $this->actions->testConnection(1)->status);

        $this->transport->enqueue(FakeTransport::json(403, ['error' => 'Forbidden', 'code' => 'invalid_key']));
        self::assertSame(ConnectionReport::REJECTED, $this->actions->testConnection(1)->status);

        $this->respond(200, [['store_key' => 'other.example.com', 'status' => 'active']]);
        $report = $this->actions->testConnection(1);
        self::assertSame([ConnectionReport::FIRST_SYNC_NEEDED, ['other.example.com']], [$report->status, $report->existingKeys]);

        $this->respond(200, [['store_key' => 'shop.example.com', 'status' => 'active']]);
        $this->respond(200, ['store_key' => 'shop.example.com', 'latest_run' => null]);
        self::assertSame(ConnectionReport::WAITING_FIRST_RUN, $this->actions->testConnection(1)->status);

        $this->respond(200, [['store_key' => 'shop.example.com', 'status' => 'active']]);
        $this->respond(200, ['store_key' => 'shop.example.com', 'latest_run' => ['run_id' => 3]]);
        self::assertSame(ConnectionReport::FOUND, $this->actions->testConnection(1)->status);
        self::assertSame(self::A, $this->state->get(1)->accountId);
    }

    public function testClearTargetsTheCurrentStoreAfterTheConnectionCheck(): void
    {
        $this->pushed(1, 'shop.example.com');
        $this->respond(200, []);                       // GET /stores (connection check)
        $this->respond(200, ['products_deleted' => 3]);
        $result = $this->actions->clear(1, 'admin');
        self::assertTrue($result->ok, $result->message);
        self::assertSame(['GET https://engine.test/stores', 'DELETE https://engine.test/stores/shop.example.com/catalog'],
            $this->methods());
        self::assertSame('admin', $this->log->recent(1)[0]['admin_user']);
    }

    public function testClearIsRefusedWhenTheConnectionNowPointsAtAnotherAccount(): void
    {
        $this->pushed(1, 'shop.example.com');
        $this->respond(200, [], self::B);
        $result = $this->actions->clear(1, 'admin');
        self::assertFalse($result->ok);
        self::assertStringContainsString('different AnswerEng connection', $result->message);
        self::assertSame(['GET https://engine.test/stores'], $this->methods());
    }

    public function testClearIsRefusedWhileAnotherViewIsUsingTheStore(): void
    {
        $this->pushed(1, 'shop.example.com');
        $this->pushed(2, 'shop.example.com');
        $this->settings->put(1, ['storeKey' => 'shop.example.com']);   // still current for 1 too
        $this->respond(200, []);
        $result = $this->actions->clear(1, 'admin');
        self::assertFalse($result->ok);
        self::assertStringContainsString('Store 2', $result->message);
    }

    public function testDeleteNeedsTheExactKeyAndTheAcknowledgement(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        self::assertFalse($this->actions->delete(1, $id, 'Shop.example.com', true, 'admin')->ok);
        self::assertFalse($this->actions->delete(1, $id, 'shop.example.com', false, 'admin')->ok);
        self::assertSame([], $this->transport->requests);
    }

    public function testDeletingTheCurrentStoreSchedulesItAndDisablesTheView(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        $this->respond(200, []);
        $this->respond(202, ['store_key' => 'shop.example.com', 'status' => 'pending_deletion',
            'purge_after' => '2026-10-14T12:00:00+00:00']);
        $result = $this->actions->delete(1, $id, 'shop.example.com', true, 'admin');
        self::assertTrue($result->ok, $result->message);
        self::assertStringContainsString('2026-10-14', $result->message);
        self::assertSame('pending_deletion', $this->registry->get($id)?->remoteStatus);
        self::assertSame([1], $this->disabler->disabled);
        self::assertSame('{"confirm_store_key":"shop.example.com"}', $this->transport->requests[1]['body']);
    }

    public function testDeletingAPreviousStoreLeavesCurrentSyncingAlone(): void
    {
        $old = $this->pushed(1, 'old.example.com');
        $this->pushed(1, 'new.example.com');
        $this->respond(200, []);
        $this->respond(202, ['purge_after' => '2026-10-14T12:00:00+00:00']);
        self::assertTrue($this->actions->delete(1, $old, 'old.example.com', true, 'admin')->ok);
        self::assertSame([], $this->disabler->disabled);
    }

    public function testDeleteReportsARunInProgress(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        $this->respond(200, []);
        $this->respond(409, ['code' => 'run_in_progress']);
        $result = $this->actions->delete(1, $id, 'shop.example.com', true, 'admin');
        self::assertFalse($result->ok);
        self::assertStringContainsString('analysis run is in progress', $result->message);
        self::assertSame('active', $this->registry->get($id)?->remoteStatus);
    }

    public function testCancelRestoresButNeverReEnables(): void
    {
        $id = $this->pushed(1, 'shop.example.com');
        $this->registry->setStatus($id, 'pending_deletion', $this->clock->now()->modify('+7 days'));
        $this->settings->put(1, ['enabled' => false, 'storeKey' => 'shop.example.com']);
        $this->respond(200, []);
        $this->respond(200, ['status' => 'active']);
        self::assertTrue($this->actions->cancel(1, $id, 'admin')->ok);
        self::assertSame('active', $this->registry->get($id)?->remoteStatus);
        self::assertFalse($this->settings->forStore(1)->enabled);

        $this->registry->setStatus($id, 'pending_deletion', $this->clock->now());
        $this->respond(200, []);
        $this->respond(409, ['code' => 'purge_in_progress']);
        $result = $this->actions->cancel(1, $id, 'admin');
        self::assertFalse($result->ok);
        self::assertStringContainsString('can no longer be cancelled', $result->message);
    }

    public function testForgetIsLocalAndOnlyForPreviousStores(): void
    {
        $current = $this->pushed(1, 'shop.example.com');
        self::assertFalse($this->actions->forget(1, $current, 'admin')->ok);
        $this->settings->put(1, ['storeKey' => 'renamed.example.com']);
        self::assertTrue($this->actions->forget(1, $current, 'admin')->ok);
        self::assertNull($this->registry->get($current));
        self::assertSame([], $this->transport->requests);
    }

    public function testRefreshMarksPurgedStoresDeletedAndPendingOnesPending(): void
    {
        $gone = $this->pushed(1, 'gone.example.com');
        $this->registry->setStatus($gone, 'pending_deletion', $this->clock->now()->modify('-1 day'));
        $waiting = $this->pushed(1, 'shop.example.com');
        $this->respond(200, [['store_key' => 'shop.example.com', 'status' => 'pending_deletion',
            'purge_after' => '2026-10-14T12:00:00+00:00']]);
        $this->actions->refreshStatuses(1);
        self::assertSame('deleted', $this->registry->get($gone)?->remoteStatus);
        self::assertSame(['pending_deletion', '2026-10-14 12:00:00'],
            [$this->registry->get($waiting)?->remoteStatus, $this->registry->get($waiting)?->purgeAfter?->format('Y-m-d H:i:s')]);
    }
}
```

`Test/Db/SummaryProviderTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Admin\SummaryProvider;
use Fullspec\AnswerEng\Model\Admin\SummaryView;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\ArrayCache;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Fullspec\AnswerEng\Test\Support\FixedClock;
use Fullspec\AnswerEng\Test\Unit\Engine\FakeTransport;
use Psr\Log\NullLogger;

final class SummaryProviderTest extends DbTestCase
{
    private FakeSettings $settings;
    private FakeTransport $transport;
    private ArrayCache $cache;
    private SyncStateRepository $state;
    private SummaryProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new FakeSettings();
        $this->settings->put(1, ['storeKey' => 'demo-outdoor-store']);
        $this->transport = new FakeTransport();
        $this->cache = new ArrayCache();
        $this->state = new SyncStateRepository($this->db);
        $this->provider = new SummaryProvider($this->settings, $this->state,
            new EngineClient($this->transport, new NullLogger()), $this->cache, new FixedClock());
    }

    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__) . "/Fixtures/read_api/$name"), true);
    }

    public function testFreshResultIsCachedAndSnapshotted(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary.json')));
        $view = $this->provider->forView(1);
        self::assertSame(SummaryView::FRESH, $view->state);
        self::assertNotNull($view->rate);
        self::assertSame('https://app.answereng.io/stores/demo-outdoor-store', $view->dashboardUrl);
        self::assertSame(SummaryView::FRESH, $this->provider->forView(1)->state);
        self::assertCount(1, $this->transport->requests);
        self::assertNotNull($this->state->get(1)->snapshotJson);
    }

    public function testUnreachableEngineFallsBackToAMatchingSnapshotMarkedStale(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary.json')));
        $this->provider->forView(1);
        $this->cache->items = [];
        $this->transport->enqueue(FakeTransport::json(504, null))->enqueue(FakeTransport::json(504, null));
        $view = $this->provider->forView(1);
        self::assertSame(SummaryView::STALE, $view->state);
        self::assertSame('2026-10-07 12:00:00', $view->fetchedAt?->format('Y-m-d H:i:s'));
    }

    public function testASnapshotFromAnotherConnectionIsNeverShown(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary.json')));
        $this->provider->forView(1);
        $this->settings->put(1, ['storeKey' => 'demo-outdoor-store', 'apiKey' => 'ae_other_workspace']);
        $this->transport->enqueue(FakeTransport::json(504, null))->enqueue(FakeTransport::json(504, null));
        self::assertSame(SummaryView::UNAVAILABLE, $this->provider->forView(1)->state);
    }

    public function testNotSyncedNoRunPendingAndDisabled(): void
    {
        $this->transport->enqueue(FakeTransport::json(404, ['code' => 'unknown_store']));
        self::assertSame(SummaryView::NOT_SYNCED, $this->provider->forView(1)->state);
        $this->transport->enqueue(FakeTransport::json(200, $this->fixture('summary_no_runs.json')));
        self::assertSame(SummaryView::NO_RUN, $this->provider->forView(1)->state);
        $this->cache->items = [];
        $this->transport->enqueue(FakeTransport::json(409, ['code' => 'store_pending_deletion']));
        self::assertSame(SummaryView::PENDING_DELETION, $this->provider->forView(1)->state);
        $this->settings->put(1, ['enabled' => false]);
        self::assertSame(SummaryView::DISABLED, $this->provider->forView(1)->state);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --testsuite Db --filter 'RemoteStoreActionsTest|SummaryProviderTest'`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the small ports and value objects**

`Model/Admin/KeyValueCache.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

interface KeyValueCache
{
    public function load(string $key): ?string;

    public function save(string $key, string $value, int $ttlSeconds): void;
}
```

`Model/Admin/MagentoKeyValueCache.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

use Magento\Framework\App\CacheInterface;

final class MagentoKeyValueCache implements KeyValueCache
{
    private const TAG = 'ANSWERENG';

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function load(string $key): ?string
    {
        $value = $this->cache->load($key);
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function save(string $key, string $value, int $ttlSeconds): void
    {
        $this->cache->save($value, $key, [self::TAG], $ttlSeconds);
    }
}
```

`Model/Config/ConfigDisabler.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

interface ConfigDisabler
{
    /** Turn AnswerEng off for a store view (a config save: bumps its revision). */
    public function disable(int $storeId): void;
}
```

`Model/Config/MagentoConfigDisabler.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

use Fullspec\AnswerEng\Model\Sync\ConfigChangeHandler;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Model\ScopeInterface;

final class MagentoConfigDisabler implements ConfigDisabler
{
    public function __construct(
        private readonly WriterInterface $writer,
        private readonly ReinitableConfigInterface $config,
        private readonly ConfigChangeHandler $changes
    ) {
    }

    public function disable(int $storeId): void
    {
        $this->writer->save(ConfigSettingsProvider::PATH_ENABLED, '0', ScopeInterface::SCOPE_STORES, $storeId);
        $this->config->reinit();
        $this->changes->handle();
    }
}
```

`Model/Admin/ActionResult.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

final class ActionResult
{
    public function __construct(public readonly bool $ok, public readonly string $message)
    {
    }
}
```

`Model/Admin/ConnectionReport.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

final class ConnectionReport
{
    public const NO_KEY = 'no_key';
    public const UNREACHABLE = 'unreachable';
    public const REJECTED = 'rejected';
    public const FOUND = 'found';
    public const WAITING_FIRST_RUN = 'waiting_first_run';
    public const FIRST_SYNC_NEEDED = 'first_sync_needed';

    /** @param list<string> $existingKeys */
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly array $existingKeys = []
    ) {
    }

    public function ok(): bool
    {
        return in_array($this->status, [self::FOUND, self::WAITING_FIRST_RUN, self::FIRST_SYNC_NEEDED], true);
    }
}
```

`Model/Admin/SummaryView.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

final class SummaryView
{
    public const DISABLED = 'disabled';
    public const FRESH = 'fresh';
    public const STALE = 'stale';
    public const UNAVAILABLE = 'unavailable';
    public const NOT_SYNCED = 'not_synced';
    public const NO_RUN = 'no_run';
    public const PENDING_DELETION = 'pending_deletion';

    public function __construct(
        public readonly string $state,
        public readonly string $dashboardUrl,
        public readonly ?float $rate = null,
        public readonly ?float $low = null,
        public readonly ?float $high = null,
        public readonly ?string $runStartedAt = null,
        public readonly ?\DateTimeImmutable $fetchedAt = null
    ) {
    }
}
```

- [ ] **Step 4: Write the summary provider**

`Model/Admin/SummaryProvider.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Support\Clock;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;

/**
 * Headline visibility for the panel (spec §7). Cache entries and the stored
 * fallback snapshot are keyed by the connection fingerprint, so results from
 * another store, key or engine are never shown; a fallback is labeled stale.
 */
final class SummaryProvider
{
    public const CACHE_SECONDS = 900;
    public const DASHBOARD = 'https://app.answereng.io/stores/';

    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly EngineClient $engine,
        private readonly KeyValueCache $cache,
        private readonly Clock $clock
    ) {
    }

    public function forView(int $storeId): SummaryView
    {
        $s = $this->settings->forStore($storeId);
        $url = self::DASHBOARD . rawurlencode($s->storeKey);
        if (!$s->enabled || $s->apiKey === '' || $s->storeKey === '') {
            return new SummaryView(SummaryView::DISABLED, $url);
        }
        $key = 'answereng_summary_' . $s->fingerprint();
        $cached = $this->cache->load($key);
        if ($cached !== null) {
            return $this->fromJson($cached, SummaryView::FRESH, $url, null);
        }
        $r = $this->engine->summary($s->connection(), $s->storeKey);
        if ($r->isSuccess() && $r->json !== null) {
            $json = json_encode($r->json, JSON_THROW_ON_ERROR);
            $this->cache->save($key, $json, self::CACHE_SECONDS);
            $this->state->saveSnapshot($storeId, $s->fingerprint(), $json, $this->clock->now());
            return $this->fromJson($json, SummaryView::FRESH, $url, null);
        }
        if ($r->status === 404 && $r->code() === 'unknown_store') {
            return new SummaryView(SummaryView::NOT_SYNCED, $url);
        }
        if ($r->status === 409 && $r->code() === 'store_pending_deletion') {
            return new SummaryView(SummaryView::PENDING_DELETION, $url);
        }
        $state = $this->state->get($storeId);
        if ($state->snapshotJson !== null && $state->snapshotFingerprint === $s->fingerprint()) {
            return $this->fromJson($state->snapshotJson, SummaryView::STALE, $url, $state->snapshotFetchedAt);
        }
        return new SummaryView(SummaryView::UNAVAILABLE, $url);
    }

    private function fromJson(string $json, string $state, string $url, ?\DateTimeImmutable $fetchedAt): SummaryView
    {
        $data = json_decode($json, true) ?: [];
        if (($data['latest_run'] ?? null) === null) {
            return new SummaryView($state === SummaryView::STALE ? SummaryView::STALE : SummaryView::NO_RUN, $url,
                null, null, null, null, $fetchedAt);
        }
        $v = is_array($data['visibility'] ?? null) ? $data['visibility'] : [];
        $num = static fn ($x): ?float => is_numeric($x) ? (float) $x : null;
        return new SummaryView($state, $url, $num($v['rate'] ?? null), $num($v['low'] ?? null), $num($v['high'] ?? null),
            is_string($data['latest_run']['started_at'] ?? null) ? $data['latest_run']['started_at'] : null, $fetchedAt);
    }
}
```

- [ ] **Step 5: Write the remote-store actions**

`Model/Admin/RemoteStoreActions.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

use Fullspec\AnswerEng\Model\Config\ConfigDisabler;
use Fullspec\AnswerEng\Model\Config\Settings;
use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Engine\EngineResult;
use Fullspec\AnswerEng\Model\Registry\RemoteStore;
use Fullspec\AnswerEng\Model\Registry\RemoteStoreRegistry;
use Fullspec\AnswerEng\Model\Registry\RoleResolver;
use Fullspec\AnswerEng\Model\Support\Clock;
use Fullspec\AnswerEng\Model\Support\Ts;
use Fullspec\AnswerEng\Model\Sync\Classifier;
use Fullspec\AnswerEng\Model\Sync\ErrorDetail;
use Fullspec\AnswerEng\Model\Sync\OutcomeKind;
use Fullspec\AnswerEng\Model\Sync\SyncLog;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;

/**
 * Spec §7 / §7.1. Every destructive action (clear, delete, cancel) first runs
 * the connection check — same engine URL and the live X-AnswerEng-Account
 * equal to the identity's — and refuses while another view uses the store.
 */
final class RemoteStoreActions
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly SyncLog $log,
        private readonly EngineClient $engine,
        private readonly Classifier $classifier,
        private readonly RemoteStoreRegistry $registry,
        private readonly RoleResolver $roles,
        private readonly ConfigDisabler $disabler,
        private readonly Clock $clock
    ) {
    }

    public function testConnection(int $storeId): ConnectionReport
    {
        $s = $this->settings->forStore($storeId);
        if ($s->apiKey === '') {
            return new ConnectionReport(ConnectionReport::NO_KEY,
                (string) __('Enter an API key first. Nothing is sent to AnswerEng without one.'));
        }
        $r = $this->engine->listStores($s->connection());
        if ($r->status === null || $r->status >= 500) {
            return new ConnectionReport(ConnectionReport::UNREACHABLE,
                (string) __('AnswerEng is unreachable (it may be waking up — try again in a minute).'));
        }
        if (!$r->isSuccess()) {
            return new ConnectionReport(ConnectionReport::REJECTED, $this->refusal($r));
        }
        $this->noteAccount($s, $r);
        $keys = $this->storeKeys($r);
        if (!in_array($s->storeKey, $keys, true)) {
            return new ConnectionReport(ConnectionReport::FIRST_SYNC_NEEDED, (string) __(
                'Connected — first sync needed (store "%1" will be created on first sync; it uses 1 of your plan\'s stores).',
                $s->storeKey
            ), $keys);
        }
        $summary = $this->engine->summary($s->connection(), $s->storeKey);
        if ($summary->isSuccess() && ($summary->json['latest_run'] ?? null) === null) {
            return new ConnectionReport(ConnectionReport::WAITING_FIRST_RUN,
                (string) __('Connected — waiting for the first analysis run.'), $keys);
        }
        return new ConnectionReport(ConnectionReport::FOUND,
            (string) __('Connected — store "%1" found.', $s->storeKey), $keys);
    }

    public function clear(int $storeId, string $adminUser): ActionResult
    {
        $rs = $this->roles->currentFor($storeId);
        if ($rs === null) {
            return new ActionResult(false, (string) __('This store view has no current AnswerEng store to clear.'));
        }
        $s = $this->settings->forStore($storeId);
        if (($error = $this->guard($rs, $s) ?? $this->crossView($rs, $storeId)) !== null) {
            return new ActionResult(false, $error);
        }
        $r = $this->engine->clearCatalog($s->connection(), $rs->storeKey);
        $o = $this->classifier->classify($r, true);
        if ($o->kind === OutcomeKind::Success || $o->kind === OutcomeKind::AlreadyClear) {
            $this->log->append($storeId, $this->clock->now(), 'admin', 'catalog_cleared', $rs->storeKey, $adminUser);
            return new ActionResult(true, (string) __('Cleared the AnswerEng catalog for "%1". History and results are '
                . 'kept, and the store still counts toward your plan. The next sync restores the catalog.', $rs->storeKey));
        }
        return new ActionResult(false, $this->refusal($r));
    }

    public function delete(int $storeId, int $identityId, string $typedKey, bool $acknowledged, string $adminUser): ActionResult
    {
        $rs = $this->identityForView($identityId, $storeId);
        if ($rs === null) {
            return new ActionResult(false, (string) __('That AnswerEng store is not linked to this store view.'));
        }
        if ($typedKey !== $rs->storeKey || !$acknowledged) {
            return new ActionResult(false, (string) __('Type the store key exactly and tick the box to confirm.'));
        }
        $s = $this->settings->forStore($storeId);
        if (($error = $this->guard($rs, $s) ?? $this->crossView($rs, $storeId)) !== null) {
            return new ActionResult(false, $error);
        }
        $wasCurrent = $this->roles->isCurrent($rs, $storeId);
        $r = $this->engine->deleteStore($s->connection(), $rs->storeKey);
        if ($r->isSuccess()) {
            $purgeAfter = $this->purgeAfter($r) ?? $this->clock->now()->modify('+7 days');
            $this->registry->setStatus($rs->id, 'pending_deletion', $purgeAfter);
            if ($wasCurrent) {
                $this->disabler->disable($storeId);
            }
            $this->log->append($storeId, $this->clock->now(), 'admin', 'deletion_requested',
                $rs->storeKey . ' purge_after=' . Ts::fmt($purgeAfter), $adminUser);
            $message = (string) __('AnswerEng will permanently delete "%1" on %2 UTC. You can cancel until then.',
                $rs->storeKey, $purgeAfter->format('Y-m-d H:i'));
            return new ActionResult(true, $wasCurrent
                ? $message . ' ' . __('Syncing for this store view is now disabled.') : $message);
        }
        if ($r->code() === 'store_pending_deletion') {
            $this->registry->setStatus($rs->id, 'pending_deletion', $this->purgeAfter($r));
        }
        return new ActionResult(false, $this->refusal($r, $rs->storeKey));
    }

    public function cancel(int $storeId, int $identityId, string $adminUser): ActionResult
    {
        $rs = $this->identityForView($identityId, $storeId);
        if ($rs === null) {
            return new ActionResult(false, (string) __('That AnswerEng store is not linked to this store view.'));
        }
        $s = $this->settings->forStore($storeId);
        if (($error = $this->guard($rs, $s)) !== null) {
            return new ActionResult(false, $error);
        }
        $r = $this->engine->restoreStore($s->connection(), $rs->storeKey);
        if ($r->isSuccess()) {
            $this->registry->setStatus($rs->id, 'active', null);
            $this->log->append($storeId, $this->clock->now(), 'admin', 'deletion_cancelled', $rs->storeKey, $adminUser);
            return new ActionResult(true, (string) __('Deletion of "%1" cancelled. Re-enable syncing for this store view '
                . 'when you are ready.', $rs->storeKey));
        }
        return new ActionResult(false, $this->refusal($r, $rs->storeKey));
    }

    public function forget(int $storeId, int $identityId, string $adminUser): ActionResult
    {
        $rs = $this->identityForView($identityId, $storeId);
        if ($rs === null) {
            return new ActionResult(false, (string) __('That AnswerEng store is not linked to this store view.'));
        }
        if ($this->roles->isCurrent($rs, $storeId)) {
            return new ActionResult(false, (string) __('"%1" is this store view\'s current AnswerEng store. Change the '
                . 'store key or disable the view before forgetting it.', $rs->storeKey));
        }
        $this->registry->forget($rs->id, $storeId);
        $this->log->append($storeId, $this->clock->now(), 'admin', 'forgotten', $rs->storeKey, $adminUser);
        return new ActionResult(true, (string) __('Forgot "%1" here. Nothing was changed in AnswerEng: the store and its '
            . 'plan slot are untouched.', $rs->storeKey));
    }

    /** Reconcile remote statuses for identities reachable through the CURRENT connection. */
    public function refreshStatuses(int $storeId): void
    {
        $s = $this->settings->forStore($storeId);
        $identities = array_filter($this->registry->forView($storeId),
            static fn (RemoteStore $r) => $r->engineUrl === $s->engineUrl);
        if ($s->apiKey === '' || $identities === []) {
            return;
        }
        $r = $this->engine->listStores($s->connection());
        if (!$r->isSuccess() || $r->account === null) {
            return;
        }
        $this->noteAccount($s, $r);
        $remote = [];
        foreach ((array) $r->json as $item) {
            if (is_array($item) && is_string($item['store_key'] ?? null)) {
                $remote[$item['store_key']] = $item;
            }
        }
        $now = $this->clock->now();
        foreach ($identities as $rs) {
            if ($rs->accountId !== $r->account) {
                continue;
            }
            $item = $remote[$rs->storeKey] ?? null;
            if ($item !== null) {
                $pending = in_array($item['status'] ?? 'active', ['pending_deletion', 'purging'], true);
                $purge = is_string($item['purge_after'] ?? null) ? new \DateTimeImmutable($item['purge_after']) : null;
                $this->registry->setStatus($rs->id, $pending ? 'pending_deletion' : 'active', $pending ? $purge : null);
            } elseif ($rs->remoteStatus === 'pending_deletion' && $rs->purgeAfter !== null && $rs->purgeAfter <= $now) {
                $this->registry->setStatus($rs->id, 'deleted', null);
            }
        }
    }

    private function guard(RemoteStore $rs, Settings $s): ?string
    {
        $mismatch = (string) __('"%1" was synced through a different AnswerEng connection (engine %2, account %3). '
            . 'Reconnect with that connection to manage it, or Forget it here.', $rs->storeKey, $rs->engineUrl, $rs->accountId);
        if ($s->apiKey === '') {
            return (string) __('Enter an API key first.');
        }
        if ($s->engineUrl !== $rs->engineUrl) {
            return $mismatch;
        }
        $r = $this->engine->listStores($s->connection());
        if ($r->status === null || $r->status >= 500) {
            return (string) __('AnswerEng is unreachable (it may be waking up — try again in a minute).');
        }
        if (!$r->isSuccess()) {
            return $this->refusal($r);
        }
        $this->noteAccount($s, $r);
        return $r->account === $rs->accountId ? null : $mismatch;
    }

    private function crossView(RemoteStore $rs, int $storeId): ?string
    {
        $others = array_values(array_diff($this->roles->activeUsers($rs->id), [$storeId]));
        if ($others === []) {
            return null;
        }
        return (string) __('"%1" is the active AnswerEng store for store view %2. Disable that view or change its '
            . 'store key first.', $rs->storeKey, implode(', ', array_map([$this->settings, 'storeName'], $others)));
    }

    private function identityForView(int $identityId, int $storeId): ?RemoteStore
    {
        $rs = $this->registry->get($identityId);
        return ($rs !== null && in_array($storeId, $this->registry->viewsOf($identityId), true)) ? $rs : null;
    }

    private function noteAccount(Settings $s, EngineResult $r): void
    {
        if ($r->account !== null) {
            $this->state->recordAccount($s->storeId, $r->account, $s->apiKeyHash());
        }
    }

    /** @return list<string> */
    private function storeKeys(EngineResult $r): array
    {
        $keys = [];
        foreach ((array) $r->json as $item) {
            if (is_array($item) && is_string($item['store_key'] ?? null)) {
                $keys[] = $item['store_key'];
            }
        }
        return $keys;
    }

    private function purgeAfter(EngineResult $r): ?\DateTimeImmutable
    {
        $value = $r->purgeAfter();
        return $value === null ? null : (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function refusal(EngineResult $r, string $storeKey = ''): string
    {
        return match ($r->code()) {
            'invalid_key' => (string) __('API key rejected or account inactive — check the key.'),
            'run_in_progress' => (string) __('An analysis run is in progress; try again in a few minutes.'),
            'unknown_store' => (string) __('AnswerEng has no store "%1" (it may already have been deleted).', $storeKey),
            'purge_in_progress' => (string) __('Deletion has already started and can no longer be cancelled.'),
            'store_pending_deletion' => (string) __('"%1" is already scheduled for deletion.', $storeKey),
            default => (string) __('AnswerEng refused the request (%1).', ErrorDetail::of($r)),
        };
    }
}
```

Add to `etc/di.xml`:

```xml
    <preference for="Fullspec\AnswerEng\Model\Admin\KeyValueCache"
                type="Fullspec\AnswerEng\Model\Admin\MagentoKeyValueCache"/>
    <preference for="Fullspec\AnswerEng\Model\Config\ConfigDisabler"
                type="Fullspec\AnswerEng\Model\Config\MagentoConfigDisabler"/>
```

- [ ] **Step 6: Run, lint, analyse, commit**

Run: `vendor/bin/phpunit`, `vendor/bin/phpcs`, `vendor/bin/phpstan analyse --no-progress` — PASS.

```bash
git add Model/Admin Model/Config/ConfigDisabler.php Model/Config/MagentoConfigDisabler.php Test/Support/ArrayCache.php Test/Support/FakeDisabler.php Test/Db/RemoteStoreActionsTest.php Test/Db/SummaryProviderTest.php etc/di.xml
git commit -m "feat: connection-checked clear/delete/cancel/forget and fingerprinted summary" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Admin status panel and actions

**Files:**
- Create: `Model/Admin/PanelViewModel.php`, `Block/Adminhtml/System/Config/StatusPanel.php`, `view/adminhtml/templates/status.phtml`, `view/adminhtml/web/js/panel-actions.js`
- Create: `etc/adminhtml/routes.xml`, `Controller/Adminhtml/Action/AbstractAction.php`, `Controller/Adminhtml/Action/SyncNow.php`, `Controller/Adminhtml/Action/TestConnection.php`, `Controller/Adminhtml/Action/Clear.php`, `Controller/Adminhtml/Action/Delete.php`, `Controller/Adminhtml/Action/Cancel.php`, `Controller/Adminhtml/Action/Forget.php`
- Modify: `etc/adminhtml/system.xml` (status group)
- Test: `Test/Db/PanelViewModelTest.php`

**Interfaces:**
- Consumes: Tasks 5–13; Magento `AuthorizationInterface`, backend `UrlInterface`.
- Produces: admin route `answereng/action/{syncnow,testconnection,clear,delete,cancel,forget}` (POST, form key, ACL); `PanelViewModel::forDefault(): array`, `::forView(int $storeId): array`.

The config page is one big `<form>`, and forms cannot nest, so every panel action is a button whose click builds a small POST form outside the config form (`panel-actions.js`). Each controller redirects back to the section with an admin message.

- [ ] **Step 1: Write the failing view-model test**

`Test/Db/PanelViewModelTest.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Admin\PanelViewModel;
use Fullspec\AnswerEng\Model\Admin\RemoteStoreActions;
use Fullspec\AnswerEng\Model\Admin\SummaryProvider;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Registry\RemoteStoreRegistry;
use Fullspec\AnswerEng\Model\Registry\RoleResolver;
use Fullspec\AnswerEng\Model\Sync\Classifier;
use Fullspec\AnswerEng\Model\Sync\SyncLog;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Fullspec\AnswerEng\Test\Support\ArrayCache;
use Fullspec\AnswerEng\Test\Support\FakeDisabler;
use Fullspec\AnswerEng\Test\Support\FakeSettings;
use Fullspec\AnswerEng\Test\Support\FixedClock;
use Fullspec\AnswerEng\Test\Unit\Engine\FakeTransport;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use Psr\Log\NullLogger;

final class PanelViewModelTest extends DbTestCase
{
    public function testViewDataCarriesRolesStatusesAndPermissions(): void
    {
        $settings = new FakeSettings();
        $s = $settings->put(1, ['storeKey' => 'new.example.com']);
        $state = new SyncStateRepository($this->db);
        $registry = new RemoteStoreRegistry($this->db);
        $clock = new FixedClock();
        $state->recordAccount(1, 'acct_a', $s->apiKeyHash());
        $old = $registry->recordPush('https://engine.test', 'acct_a', 'old.example.com', 1, $clock->now());
        $registry->recordPush('https://engine.test', 'acct_a', 'new.example.com', 1, $clock->now()->modify('+1 minute'));
        $transport = new FakeTransport();
        // refreshStatuses (GET /stores) then summary (GET /stores/{key}/summary)
        $transport->enqueue(FakeTransport::json(200, [], ['X-AnswerEng-Account' => 'acct_a']))
            ->enqueue(FakeTransport::json(404, ['code' => 'unknown_store']));
        $engine = new EngineClient($transport, new NullLogger());
        $roles = new RoleResolver($settings, $state, $registry);
        $log = new SyncLog($this->db);
        $actions = new RemoteStoreActions($settings, $state, $log, $engine, new Classifier(), $registry, $roles,
            new FakeDisabler($settings), $clock);
        $auth = $this->createMock(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(static fn (string $r) => $r !== 'Fullspec_AnswerEng::delete_store');
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(static fn (string $route) => "/admin/$route");
        $vm = new PanelViewModel($settings, $state, $log, new SummaryProvider($settings, $state, $engine, new ArrayCache(), $clock),
            $actions, $registry, $roles, $auth, $url);

        $data = $vm->forView(1);
        self::assertSame('ok', $data['state']);
        self::assertSame(['current', 'previous'], array_column($data['identities'], 'role'));
        self::assertSame($old, $data['identities'][1]['id']);
        self::assertSame([true, false], [$data['canSync'], $data['canDelete']]);
        self::assertSame('not_synced', $data['summary']->state);
        self::assertSame('/admin/answereng/action/delete', $data['urls']['delete']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --testsuite Db --filter PanelViewModelTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the view model**

`Model/Admin/PanelViewModel.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Admin;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Registry\RemoteStoreRegistry;
use Fullspec\AnswerEng\Model\Registry\RoleResolver;
use Fullspec\AnswerEng\Model\Sync\SyncLog;
use Fullspec\AnswerEng\Model\Sync\SyncStateRepository;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Everything status.phtml renders, as plain arrays/value objects. */
class PanelViewModel
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncStateRepository $state,
        private readonly SyncLog $log,
        private readonly SummaryProvider $summaries,
        private readonly RemoteStoreActions $actions,
        private readonly RemoteStoreRegistry $registry,
        private readonly RoleResolver $roles,
        private readonly AuthorizationInterface $authorization,
        private readonly UrlInterface $url,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /** @return list<array<string,mixed>> one row per enabled view */
    public function forDefault(): array
    {
        $rows = [];
        foreach ($this->settings->enabledStoreIds() as $storeId) {
            $state = $this->state->get($storeId);
            $rows[] = [
                'storeId' => $storeId,
                'name' => $this->settings->storeName($storeId),
                'storeKey' => $this->settings->forStore($storeId)->storeKey,
                'state' => $state->state,
                'reason' => $state->reason,
                'lastSyncAt' => $state->lastSyncAt?->format('Y-m-d H:i'),
                'productCount' => $state->productCount,
            ];
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    public function forView(int $storeId): array
    {
        try {
            $this->actions->refreshStatuses($storeId);
        } catch (\Throwable $e) {
            $this->logger->warning('AnswerEng status refresh failed', ['exception' => get_class($e)]);
        }
        $s = $this->settings->forStore($storeId);
        $state = $this->state->get($storeId);
        $identities = [];
        foreach ($this->registry->forView($storeId) as $rs) {
            $others = array_values(array_diff($this->roles->activeUsers($rs->id), [$storeId]));
            $identities[] = [
                'id' => $rs->id,
                'storeKey' => $rs->storeKey,
                'engineUrl' => $rs->engineUrl,
                'role' => $this->roles->isCurrent($rs, $storeId) ? 'current' : 'previous',
                'status' => $rs->remoteStatus,
                'purgeAfter' => $rs->purgeAfter?->format('Y-m-d H:i'),
                'usedBy' => array_map([$this->settings, 'storeName'], $others),
            ];
        }
        return [
            'storeId' => $storeId,
            'enabled' => $s->enabled,
            'storeKey' => $s->storeKey,
            'state' => $state->state,
            'reason' => $state->reason,
            'lastSyncAt' => $state->lastSyncAt?->format('Y-m-d H:i'),
            'productCount' => $state->productCount,
            'log' => $this->log->recent($storeId),
            'summary' => $this->summaries->forView($storeId),
            'identities' => $identities,
            'canSync' => $this->authorization->isAllowed('Fullspec_AnswerEng::sync'),
            'canDelete' => $this->authorization->isAllowed('Fullspec_AnswerEng::delete_store'),
            'urls' => [
                'syncNow' => $this->url->getUrl('answereng/action/syncnow'),
                'testConnection' => $this->url->getUrl('answereng/action/testconnection'),
                'clear' => $this->url->getUrl('answereng/action/clear'),
                'delete' => $this->url->getUrl('answereng/action/delete'),
                'cancel' => $this->url->getUrl('answereng/action/cancel'),
                'forget' => $this->url->getUrl('answereng/action/forget'),
            ],
        ];
    }
}
```

- [ ] **Step 4: Write the block, template and script**

`Block/Adminhtml/System/Config/StatusPanel.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Block\Adminhtml\System\Config;

use Fullspec\AnswerEng\Model\Admin\PanelViewModel;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class StatusPanel extends Field
{
    protected $_template = 'Fullspec_AnswerEng::status.phtml';

    public function __construct(Context $context, private readonly PanelViewModel $panel, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element)
    {
        return $this->_decorateRowHtml($element, '<td colspan="4">' . $this->toHtml() . '</td>');
    }

    public function getStoreId(): int
    {
        return (int) $this->getRequest()->getParam('store');
    }

    /** @return array<string,mixed>|list<array<string,mixed>> */
    public function getPanel(): array
    {
        $storeId = $this->getStoreId();
        return $storeId > 0 ? $this->panel->forView($storeId) : $this->panel->forDefault();
    }
}
```

`view/adminhtml/templates/status.phtml`:

```php
<?php
/**
 * @var \Fullspec\AnswerEng\Block\Adminhtml\System\Config\StatusPanel $block
 * @var \Magento\Framework\Escaper $escaper
 */
$storeId = $block->getStoreId();
$panel = $block->getPanel();
$pct = static fn (?float $x): string => $x === null ? '–' : number_format($x * 100, 1) . '%';
?>
<div class="answereng-panel" data-mage-init='{"Fullspec_AnswerEng/js/panel-actions": {}}'>
<?php if ($storeId <= 0): ?>
    <p><?= $escaper->escapeHtml(__('Choose a store view (scope switcher, top left) to sync, test the connection or manage AnswerEng stores.')) ?></p>
    <?php if ($panel === []): ?>
        <p><?= $escaper->escapeHtml(__('No store view is enabled for AnswerEng yet.')) ?></p>
    <?php else: ?>
        <table class="data-grid">
            <thead><tr>
                <th><?= $escaper->escapeHtml(__('Store view')) ?></th>
                <th><?= $escaper->escapeHtml(__('Store key')) ?></th>
                <th><?= $escaper->escapeHtml(__('Status')) ?></th>
                <th><?= $escaper->escapeHtml(__('Last sync (UTC)')) ?></th>
                <th><?= $escaper->escapeHtml(__('Products')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($panel as $row): ?>
                <tr>
                    <td><?= $escaper->escapeHtml($row['name']) ?></td>
                    <td><?= $escaper->escapeHtml($row['storeKey']) ?></td>
                    <td><?= $escaper->escapeHtml($row['state'] . ($row['reason'] ? ' (' . $row['reason'] . ')' : '')) ?></td>
                    <td><?= $escaper->escapeHtml($row['lastSyncAt'] ?? '–') ?></td>
                    <td><?= $escaper->escapeHtml((string) ($row['productCount'] ?? '–')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php else: ?>
    <?php $summary = $panel['summary']; ?>
    <p>
        <strong><?= $escaper->escapeHtml(__('Sync status:')) ?></strong>
        <?= $escaper->escapeHtml($panel['state'] . ($panel['reason'] ? ' (' . $panel['reason'] . ')' : '')) ?>
        · <?= $escaper->escapeHtml(__('Last sync (UTC): %1', $panel['lastSyncAt'] ?? '–')) ?>
        · <?= $escaper->escapeHtml(__('Products: %1', (string) ($panel['productCount'] ?? '–'))) ?>
    </p>
    <p>
        <strong><?= $escaper->escapeHtml(__('AI visibility:')) ?></strong>
        <?php if (in_array($summary->state, ['fresh', 'stale'], true) && $summary->rate !== null): ?>
            <?= $escaper->escapeHtml($pct($summary->rate) . ' (' . $pct($summary->low) . '–' . $pct($summary->high) . ')') ?>
            <?php if ($summary->state === 'stale'): ?>
                <em><?= $escaper->escapeHtml(__('Stale — last fetched %1 UTC', $summary->fetchedAt?->format('Y-m-d H:i') ?? '')) ?></em>
            <?php endif; ?>
        <?php else: ?>
            <?= $escaper->escapeHtml(match ($summary->state) {
                'not_synced' => __('Not synced yet.'),
                'no_run' => __('Connected — waiting for the first analysis run.'),
                'pending_deletion' => __('This store is scheduled for deletion.'),
                'disabled' => __('Enable this store view and enter an API key to see results.'),
                default => __('Results unavailable — engine unreachable.'),
            }) ?>
        <?php endif; ?>
        <a href="<?= $escaper->escapeUrl($summary->dashboardUrl) ?>" target="_blank" rel="noopener">
            <?= $escaper->escapeHtml(__('Open full results in AnswerEng →')) ?></a>
    </p>
    <p>
        <?php if ($panel['canSync']): ?>
            <button type="button" class="action-secondary"
                    data-answereng-action="<?= $escaper->escapeHtmlAttr($panel['urls']['syncNow']) ?>"
                    data-fields='<?= $escaper->escapeHtmlAttr(json_encode(['store' => $storeId])) ?>'>
                <?= $escaper->escapeHtml(__('Sync now')) ?></button>
        <?php endif; ?>
        <button type="button" class="action-secondary"
                data-answereng-action="<?= $escaper->escapeHtmlAttr($panel['urls']['testConnection']) ?>"
                data-fields='<?= $escaper->escapeHtmlAttr(json_encode(['store' => $storeId])) ?>'>
            <?= $escaper->escapeHtml(__('Test connection')) ?></button>
        <?php if ($panel['canSync'] && array_filter($panel['identities'], static fn ($i) => $i['role'] === 'current')): ?>
            <button type="button" class="action-secondary"
                    data-answereng-action="<?= $escaper->escapeHtmlAttr($panel['urls']['clear']) ?>"
                    data-confirm-text="<?= $escaper->escapeHtmlAttr(__('Remove this store\'s products from AnswerEng and pause weekly analysis? History and results are kept, and the store still counts toward your plan. The next sync restores the catalog.')) ?>"
                    data-fields='<?= $escaper->escapeHtmlAttr(json_encode(['store' => $storeId])) ?>'>
                <?= $escaper->escapeHtml(__('Clear AnswerEng catalog')) ?></button>
        <?php endif; ?>
    </p>
    <?php if ($panel['log'] !== []): ?>
        <table class="data-grid">
            <thead><tr>
                <th><?= $escaper->escapeHtml(__('When (UTC)')) ?></th>
                <th><?= $escaper->escapeHtml(__('Source')) ?></th>
                <th><?= $escaper->escapeHtml(__('Outcome')) ?></th>
                <th><?= $escaper->escapeHtml(__('Detail')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($panel['log'] as $entry): ?>
                <tr>
                    <td><?= $escaper->escapeHtml((string) $entry['created_at']) ?></td>
                    <td><?= $escaper->escapeHtml($entry['source'] . ($entry['admin_user'] ? ' (' . $entry['admin_user'] . ')' : '')) ?></td>
                    <td><?= $escaper->escapeHtml((string) $entry['outcome']) ?></td>
                    <td><?= $escaper->escapeHtml((string) ($entry['detail'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($panel['canDelete'] && $panel['identities'] !== []): ?>
        <details class="answereng-danger-zone">
            <summary><strong><?= $escaper->escapeHtml(__('Danger zone')) ?></strong></summary>
            <?php foreach ($panel['identities'] as $identity): ?>
                <div class="answereng-identity" data-answereng-identity
                     data-store-key="<?= $escaper->escapeHtmlAttr($identity['storeKey']) ?>">
                    <p>
                        <strong><?= $escaper->escapeHtml($identity['storeKey']) ?></strong>
                        (<?= $escaper->escapeHtml($identity['role']) ?>, <?= $escaper->escapeHtml($identity['status']) ?><?=
                        $identity['purgeAfter'] ? $escaper->escapeHtml(', ' . __('purge %1 UTC', $identity['purgeAfter'])) : '' ?>)
                        <?php if ($identity['usedBy'] !== []): ?>
                            — <?= $escaper->escapeHtml(__('also active for %1', implode(', ', $identity['usedBy']))) ?>
                        <?php endif; ?>
                    </p>
                    <?php $fields = ['store' => $storeId, 'identity_id' => $identity['id']]; ?>
                    <?php if ($identity['status'] === 'active'): ?>
                        <p class="message message-error"><?= $escaper->escapeHtml(__(
                            'Deleting %1 schedules permanent removal of everything AnswerEng holds for this store: its catalog, questions, every analysis run and raw AI answer, diagnoses and drafted fixes, and its dashboard project with its reviewers and invitations. You have 7 days to cancel. After that it cannot be recovered, and the store\'s plan slot is freed. Results already shown in the dashboard will disappear.',
                            $identity['storeKey']
                        )) ?></p>
                        <label><?= $escaper->escapeHtml(__('Type the store key to confirm')) ?>
                            <input type="text" data-confirm-key autocomplete="off"></label>
                        <label><input type="checkbox" data-confirm-ack>
                            <?= $escaper->escapeHtml(__('I understand this cannot be undone after 7 days')) ?></label>
                        <button type="button" class="action-secondary" disabled data-confirm-delete
                                data-answereng-action="<?= $escaper->escapeHtmlAttr($panel['urls']['delete']) ?>"
                                data-fields='<?= $escaper->escapeHtmlAttr(json_encode($fields)) ?>'>
                            <?= $escaper->escapeHtml(__('Delete store from AnswerEng')) ?></button>
                    <?php elseif ($identity['status'] === 'pending_deletion'): ?>
                        <button type="button" class="action-secondary"
                                data-answereng-action="<?= $escaper->escapeHtmlAttr($panel['urls']['cancel']) ?>"
                                data-fields='<?= $escaper->escapeHtmlAttr(json_encode($fields)) ?>'>
                            <?= $escaper->escapeHtml(__('Cancel deletion')) ?></button>
                    <?php endif; ?>
                    <?php if ($identity['role'] === 'previous'): ?>
                        <button type="button" class="action-secondary"
                                data-answereng-action="<?= $escaper->escapeHtmlAttr($panel['urls']['forget']) ?>"
                                data-confirm-text="<?= $escaper->escapeHtmlAttr(__('Forget this store here? Nothing changes in AnswerEng: the store and its plan slot stay.')) ?>"
                                data-fields='<?= $escaper->escapeHtmlAttr(json_encode($fields)) ?>'>
                            <?= $escaper->escapeHtml(__('Forget')) ?></button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </details>
    <?php endif; ?>
<?php endif; ?>
</div>
```

`view/adminhtml/web/js/panel-actions.js`:

```js
/**
 * AnswerEng panel actions. The config page is one big <form>, so each action
 * builds its own POST form outside it. The delete button only enables when
 * the typed key matches and the box is ticked; the server re-validates both.
 */
define([], function () {
    'use strict';

    return function (config, root) {
        function scopeOf(el) {
            return el.closest('[data-answereng-identity]');
        }

        function refresh(event) {
            var scope = scopeOf(event.target);
            var button = scope && scope.querySelector('[data-confirm-delete]');
            if (!button) {
                return;
            }
            button.disabled = !(scope.querySelector('[data-confirm-key]').value === scope.getAttribute('data-store-key')
                && scope.querySelector('[data-confirm-ack]').checked);
        }

        root.addEventListener('input', refresh);
        root.addEventListener('change', refresh);
        root.addEventListener('click', function (event) {
            var button = event.target.closest('[data-answereng-action]');
            if (!button || button.disabled) {
                return;
            }
            event.preventDefault();
            var confirmText = button.getAttribute('data-confirm-text');
            if (confirmText && !window.confirm(confirmText)) {
                return;
            }
            var fields = JSON.parse(button.getAttribute('data-fields') || '{}');
            if (button.hasAttribute('data-confirm-delete')) {
                var scope = scopeOf(button);
                fields.confirm_key = scope.querySelector('[data-confirm-key]').value;
                fields.acknowledge = scope.querySelector('[data-confirm-ack]').checked ? '1' : '0';
            }
            fields.form_key = window.FORM_KEY;
            var form = document.createElement('form');
            form.method = 'post';
            form.action = button.getAttribute('data-answereng-action');
            Object.keys(fields).forEach(function (name) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = fields[name];
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
        });
    };
});
```

Add the status group to `etc/adminhtml/system.xml`, as the first group inside `<section id="answereng">`:

```xml
            <group id="status" translate="label" sortOrder="10" showInDefault="1" showInWebsite="1" showInStore="1">
                <label>Status</label>
                <field id="panel" translate="label" sortOrder="10" showInDefault="1" showInWebsite="1" showInStore="1">
                    <label>AnswerEng</label>
                    <frontend_model>Fullspec\AnswerEng\Block\Adminhtml\System\Config\StatusPanel</frontend_model>
                </field>
            </group>
```

- [ ] **Step 5: Write the routes and controllers**

`etc/adminhtml/routes.xml`:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:App/etc/routes.xsd">
    <router id="admin">
        <route id="answereng" frontName="answereng">
            <module name="Fullspec_AnswerEng"/>
        </route>
    </router>
</config>
```

`Controller/Adminhtml/Action/AbstractAction.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\ActionResult;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

/** POST-only, form-key protected (backend default), ACL via ADMIN_RESOURCE; redirects back to the section. */
abstract class AbstractAction extends Action implements HttpPostActionInterface
{
    protected function storeId(): int
    {
        return (int) $this->getRequest()->getParam('store');
    }

    protected function adminUser(): string
    {
        return (string) $this->_auth->getUser()?->getUserName();
    }

    protected function back(ActionResult $result): Redirect
    {
        $result->ok
            ? $this->messageManager->addSuccessMessage($result->message)
            : $this->messageManager->addErrorMessage($result->message);
        return $this->resultRedirectFactory->create()->setPath('adminhtml/system_config/edit',
            ['section' => 'answereng', 'store' => $this->storeId()]);
    }
}
```

`Controller/Adminhtml/Action/SyncNow.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\ActionResult;
use Fullspec\AnswerEng\Model\Sync\SyncRunner;
use Magento\Backend\App\Action\Context;

class SyncNow extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::sync';

    public function __construct(Context $context, private readonly SyncRunner $runner)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->runner->sync($this->storeId(), 'admin', true);
        return $this->back(new ActionResult(!$result->isError(), $result->message));
    }
}
```

`Controller/Adminhtml/Action/TestConnection.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\ActionResult;
use Fullspec\AnswerEng\Model\Admin\RemoteStoreActions;
use Magento\Backend\App\Action\Context;

class TestConnection extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::config';

    public function __construct(Context $context, private readonly RemoteStoreActions $actions)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $report = $this->actions->testConnection($this->storeId());
        $message = $report->message;
        if ($report->existingKeys !== []) {
            $message .= ' ' . __('Stores in this workspace: %1.', implode(', ', $report->existingKeys));
        }
        return $this->back(new ActionResult($report->ok(), $message));
    }
}
```

`Controller/Adminhtml/Action/Clear.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\RemoteStoreActions;
use Magento\Backend\App\Action\Context;

class Clear extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::sync';

    public function __construct(Context $context, private readonly RemoteStoreActions $actions)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        return $this->back($this->actions->clear($this->storeId(), $this->adminUser()));
    }
}
```

`Controller/Adminhtml/Action/Delete.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\RemoteStoreActions;
use Magento\Backend\App\Action\Context;

class Delete extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::delete_store';

    public function __construct(Context $context, private readonly RemoteStoreActions $actions)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        return $this->back($this->actions->delete(
            $this->storeId(),
            (int) $request->getParam('identity_id'),
            (string) $request->getParam('confirm_key'),
            $request->getParam('acknowledge') === '1',
            $this->adminUser()
        ));
    }
}
```

`Controller/Adminhtml/Action/Cancel.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\RemoteStoreActions;
use Magento\Backend\App\Action\Context;

class Cancel extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::delete_store';

    public function __construct(Context $context, private readonly RemoteStoreActions $actions)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        return $this->back($this->actions->cancel(
            $this->storeId(),
            (int) $this->getRequest()->getParam('identity_id'),
            $this->adminUser()
        ));
    }
}
```

`Controller/Adminhtml/Action/Forget.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Controller\Adminhtml\Action;

use Fullspec\AnswerEng\Model\Admin\RemoteStoreActions;
use Magento\Backend\App\Action\Context;

class Forget extends AbstractAction
{
    public const ADMIN_RESOURCE = 'Fullspec_AnswerEng::delete_store';

    public function __construct(Context $context, private readonly RemoteStoreActions $actions)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        return $this->back($this->actions->forget(
            $this->storeId(),
            (int) $this->getRequest()->getParam('identity_id'),
            $this->adminUser()
        ));
    }
}
```

- [ ] **Step 6: Run, lint, analyse, commit**

Run: `vendor/bin/phpunit`, `vendor/bin/phpcs`, `vendor/bin/phpstan analyse --no-progress` — PASS. (phpcs checks the `.phtml` too: every output must go through `$escaper`.)

```bash
git add Model/Admin/PanelViewModel.php Block view etc/adminhtml/routes.xml etc/adminhtml/system.xml Controller Test/Db/PanelViewModelTest.php
git commit -m "feat: admin status panel with sync, test connection and Danger zone actions" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 15: Clean uninstall

**Files:**
- Create: `Setup/Uninstall.php`

**Interfaces:**
- Produces: `bin/magento module:uninstall Fullspec_AnswerEng` removes the four tables and every `answereng/*` config row.

- [ ] **Step 1: Write the uninstaller**

`Setup/Uninstall.php`:

```php
<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

class Uninstall implements UninstallInterface
{
    private const TABLES = ['answereng_remote_store_view', 'answereng_remote_store', 'answereng_sync_log',
        'answereng_sync_state'];

    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();
        $connection = $setup->getConnection();
        foreach (self::TABLES as $table) {
            $connection->dropTable($setup->getTable($table));
        }
        $connection->delete($setup->getTable('core_config_data'), ['path LIKE ?' => 'answereng/%']);
        $setup->endSetup();
    }
}
```

- [ ] **Step 2: Lint, analyse, commit**

Run: `vendor/bin/phpcs`, `vendor/bin/phpstan analyse --no-progress` — no errors. (Behaviour is verified by the end-to-end checklist in Task 16.)

```bash
git add Setup/Uninstall.php
git commit -m "feat: clean uninstall of tables and configuration" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 16: Mock engine, local Magento, end-to-end checklist and release packaging

**Files:**
- Create: `dev/mock-engine/mock_engine.py`, `dev/mock-engine/test_mock_engine.py`, `dev/mock-engine/README.md`
- Create: `docs/dev-environment.md`, `docs/integration-checklist.md`
- Create: `bin/build-zip.sh`
- Modify: `README.md`

**Interfaces:**
- Produces: a mock engine on `127.0.0.1:8895` implementing the engine contract from `Test/Fixtures` with switchable failure modes; a documented local Magento on port 8894; an executed end-to-end checklist; `bin/build-zip.sh <version>` producing `dist/answereng-magento-<version>.zip`.

- [ ] **Step 1: Write the failing mock-engine tests**

`dev/mock-engine/test_mock_engine.py`:

```python
"""Tests for the local mock engine. Run: python3 -m unittest dev/mock-engine/test_mock_engine.py"""
import http.client
import json
import threading
import unittest

import mock_engine

KEY = "ae_test"
OTHER = "ae_other"


class MockEngineTest(unittest.TestCase):
    def setUp(self):
        self.server = mock_engine.make_server(("127.0.0.1", 0), store_cap=1)
        self.port = self.server.server_address[1]
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()

    def call(self, method, path, body=None, key=KEY):
        conn = http.client.HTTPConnection("127.0.0.1", self.port, timeout=5)
        headers = {"Content-Type": "application/json"}
        if key is not None:
            headers["x-api-key"] = key
        conn.request(method, path, None if body is None else json.dumps(body), headers)
        resp = conn.getresponse()
        raw = resp.read()
        return resp.status, (json.loads(raw) if raw else None), dict(resp.getheaders())

    def push(self, store_key="shop.example.com", products=None, key=KEY):
        return self.call("POST", "/catalog", {
            "store_key": store_key, "brand_names": ["Acme"], "competitors": [],
            "products": products if products is not None else [
                {"sku": "S1", "title": "Boot", "description": "", "price": 1.0, "category": "", "attributes": {}}]},
            key=key)

    def test_push_lists_and_carries_the_account_header(self):
        status, _, headers = self.push()
        self.assertEqual(200, status)
        self.assertRegex(headers["X-AnswerEng-Account"], r"^acct_[0-9a-f]{32}$")
        status, stores, _ = self.call("GET", "/stores")
        self.assertEqual([("shop.example.com", "active")], [(s["store_key"], s["status"]) for s in stores])

    def test_validation_store_cap_and_ownership(self):
        status, body, _ = self.push(products=[])
        self.assertEqual((422, "validation_failed"), (status, body["code"]))
        self.push()
        status, body, _ = self.push(store_key="second.example.com")
        self.assertEqual((403, "store_limit", 1), (status, body["code"], body["limit"]))
        status, body, _ = self.push(key=OTHER)
        self.assertEqual((403, "store_owned_elsewhere"), (status, body["code"]))

    def test_missing_key_is_a_gateway_403(self):
        status, body, _ = self.call("GET", "/stores", key=None)
        self.assertEqual((403, "invalid_key", "Forbidden"), (status, body["code"], body["error"]))

    def test_clear_delete_restore_lifecycle(self):
        self.push()
        status, body, _ = self.call("DELETE", "/stores/shop.example.com/catalog")
        self.assertEqual((200, 1), (status, body["products_deleted"]))
        status, body, _ = self.call("DELETE", "/stores/shop.example.com", {"confirm_store_key": "nope"})
        self.assertEqual((422, "confirmation_mismatch"), (status, body["code"]))
        status, body, _ = self.call("DELETE", "/stores/shop.example.com", {"confirm_store_key": "shop.example.com"})
        self.assertEqual((202, "pending_deletion"), (status, body["status"]))
        status, body, _ = self.push()
        self.assertEqual((409, "store_pending_deletion"), (status, body["code"]))
        status, body, _ = self.call("POST", "/stores/shop.example.com/restore")
        self.assertEqual((200, "active"), (status, body["status"]))

    def mode(self, name):
        self.assertEqual(200, self.call("POST", "/__mode", {"mode": name}, key=None)[0])

    def test_modes(self):
        self.push()

        self.mode("cold-504")
        self.assertEqual(504, self.push()[0])

        self.mode("throttle-429")
        status, body, headers = self.push()
        self.assertEqual((429, "throttled", "60"), (status, body["code"], headers["Retry-After"]))

        self.mode("missing-code")
        status, body, _ = self.push()
        self.assertEqual(403, status)
        self.assertNotIn("code", body)

        self.mode("run-in-progress")   # applies to clear and delete, like the real engine
        status, body, _ = self.call("DELETE", "/stores/shop.example.com/catalog")
        self.assertEqual((409, "run_in_progress"), (status, body["code"]))

        self.mode("purging")
        status, body, _ = self.call("POST", "/stores/shop.example.com/restore")
        self.assertEqual((409, "purge_in_progress"), (status, body["code"]))

        self.mode("normal")
        self.assertEqual(200, self.push()[0])

    def test_summary_serves_fixtures(self):
        self.push()
        status, body, _ = self.call("GET", "/stores/shop.example.com/summary")
        self.assertEqual((200, "shop.example.com"), (status, body["store_key"]))
        self.assertIsNotNone(body["latest_run"])
        status, body, _ = self.call("GET", "/stores/unknown.example.com/summary")
        self.assertEqual((404, "unknown_store"), (status, body["code"]))


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd dev/mock-engine && python3 -m unittest test_mock_engine.py -v; cd ../..`
Expected: FAIL — `ModuleNotFoundError: mock_engine`.

- [ ] **Step 3: Write the mock engine**

`dev/mock-engine/mock_engine.py`:

```python
"""Local stand-in for the AnswerEng engine connector API (spec §11).

Serves the frozen contract from Test/Fixtures: error bodies from
error_codes.json and summaries from read_api/. Each API key maps to a stable
fake account (acct_ + sha256(key)[:32]), so two keys simulate two
workspaces. Failure modes are switched with POST /__mode {"mode": "..."}.

Run:  python3 dev/mock-engine/mock_engine.py --port 8895 --store-cap 1
"""
import argparse
import datetime
import hashlib
import json
import pathlib
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import unquote

ROOT = pathlib.Path(__file__).resolve().parents[2]
FIXTURES = ROOT / "Test" / "Fixtures"
RECEIVED = pathlib.Path(__file__).resolve().parent / "received"
ERRORS = json.loads((FIXTURES / "error_codes.json").read_text())
MODES = {"normal", "cold-504", "slow", "throttle-429", "invalid-key", "store-limit", "product-limit",
         "ownership", "run-in-progress", "missing-code", "purging"}


def account_for(key):
    return "acct_" + hashlib.sha256(key.encode()).hexdigest()[:32]


def err(code, **extra):
    body = dict(ERRORS["lambda"][code])
    body.update(extra)
    return body


class State:
    def __init__(self, store_cap):
        self.store_cap = store_cap
        self.mode = "normal"
        self.stores = {}   # key -> {"owner", "products", "status", "purge_after", "id"}
        self.next_id = 1


def make_server(address, store_cap=1):
    state = State(store_cap)

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, fmt, *args):  # keep test output quiet
            pass

        def _send(self, status, body, account=None, headers=None):
            raw = b"" if body is None else json.dumps(body).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(raw)))
            if account:
                self.send_header("X-AnswerEng-Account", account)
            for name, value in (headers or {}).items():
                self.send_header(name, value)
            self.end_headers()
            self.wfile.write(raw)

        def _body(self):
            length = int(self.headers.get("Content-Length") or 0)
            if not length:
                return None
            try:
                return json.loads(self.rfile.read(length))
            except ValueError:
                return None

        def _route(self, method):
            path = self.path.split("?")[0]
            body = self._body()
            if path == "/__mode" and method == "POST":
                mode = (body or {}).get("mode", "normal")
                if mode not in MODES:
                    return self._send(400, {"error": "unknown mode", "modes": sorted(MODES)})
                state.mode = mode
                return self._send(200, {"mode": mode})

            key = self.headers.get("x-api-key")
            if not key or state.mode == "invalid-key":
                return self._send(403, ERRORS["gateway"]["INVALID_API_KEY"])
            acct = account_for(key)
            mode = state.mode
            if mode == "cold-504":
                return self._send(504, None)
            if mode == "slow":
                time.sleep(35)
            if mode == "throttle-429":
                return self._send(429, ERRORS["gateway"]["THROTTLED"], headers={"Retry-After": "60"})
            if mode == "missing-code":
                return self._send(403, {"error": "refused"}, acct)

            parts = [unquote(p) for p in path.strip("/").split("/")]
            if parts == ["catalog"] and method == "POST":
                return self._push(body, acct)
            if parts == ["stores"] and method == "GET":
                return self._send(200, [
                    {"store_key": k, "latest_run_at": None, "status": v["status"], "purge_after": v["purge_after"]}
                    for k, v in sorted(state.stores.items()) if v["owner"] == acct], acct)
            if len(parts) >= 2 and parts[0] == "stores":
                store_key = parts[1]
                store = state.stores.get(store_key)
                if store is None or store["owner"] != acct:
                    return self._send(404, err("unknown_store"), acct)
                rest = parts[2:]
                if rest == ["summary"] and method == "GET":
                    if store["status"] != "active":
                        return self._send(409, err("store_pending_deletion", purge_after=store["purge_after"]), acct)
                    name = "summary.json" if store["products"] else "summary_no_runs.json"
                    summary = json.loads((FIXTURES / "read_api" / name).read_text())
                    summary["store_key"] = store_key
                    return self._send(200, summary, acct)
                if rest == ["catalog"] and method == "DELETE":
                    if mode == "run-in-progress":
                        return self._send(409, err("run_in_progress"), acct)
                    if store["status"] != "active":
                        return self._send(409, err("store_pending_deletion", purge_after=store["purge_after"]), acct)
                    deleted, store["products"] = len(store["products"]), []
                    return self._send(200, {"store_key": store_key, "products_deleted": deleted}, acct)
                if rest == [] and method == "DELETE":
                    if (body or {}).get("confirm_store_key") != store_key:
                        return self._send(422, err("confirmation_mismatch"), acct)
                    if mode == "run-in-progress":
                        return self._send(409, err("run_in_progress"), acct)
                    if store["status"] == "active":
                        store["status"] = "pending_deletion"
                        store["purge_after"] = (datetime.datetime.now(datetime.timezone.utc)
                                                + datetime.timedelta(days=7)).isoformat()
                    return self._send(202, {"store_key": store_key, "status": "pending_deletion",
                                            "purge_after": store["purge_after"]}, acct)
                if rest == ["restore"] and method == "POST":
                    if mode == "purging":
                        return self._send(409, err("purge_in_progress"), acct)
                    store["status"], store["purge_after"] = "active", None
                    return self._send(200, {"store_key": store_key, "status": "active"}, acct)
            return self._send(404, err("invalid_request", error="unknown resource"), acct)

        def _push(self, body, acct):
            errors = validate(body)
            if errors:
                return self._send(422, err("validation_failed", errors=errors))
            mode = state.mode
            if mode == "product-limit":
                return self._send(403, err("product_limit"), acct)
            if mode == "store-limit":
                return self._send(403, err("store_limit"), acct)
            if mode == "ownership":
                return self._send(403, err("store_owned_elsewhere"), acct)
            key = body["store_key"]
            store = state.stores.get(key)
            if store is not None and store["owner"] != acct:
                return self._send(403, err("store_owned_elsewhere"), acct)
            if store is None:
                owned = sum(1 for s in state.stores.values() if s["owner"] == acct)
                if owned >= state.store_cap:
                    return self._send(403, err("store_limit", limit=state.store_cap,
                                               error=f"plan allows {state.store_cap} store(s)"), acct)
                store = state.stores[key] = {"owner": acct, "products": [], "status": "active",
                                             "purge_after": None, "id": state.next_id}
                state.next_id += 1
            if store["status"] != "active":
                return self._send(409, err("store_pending_deletion", purge_after=store["purge_after"]), acct)
            store["products"] = body["products"]
            RECEIVED.mkdir(exist_ok=True)
            stamp = datetime.datetime.now().strftime("%Y%m%dT%H%M%S%f")
            (RECEIVED / f"{stamp}-{key.replace('/', '_')}.json").write_text(json.dumps(body, indent=1))
            return self._send(200, {"store_id": store["id"]}, acct)

        def do_GET(self):
            self._route("GET")

        def do_POST(self):
            self._route("POST")

        def do_DELETE(self):
            self._route("DELETE")

    return ThreadingHTTPServer(address, Handler)


def validate(body):
    """A pydantic-shaped subset of the engine's CatalogPush validation."""
    errors = []

    def bad(loc, typ, msg):
        errors.append({"type": typ, "loc": loc, "msg": msg, "input": None})

    if not isinstance(body, dict):
        bad([], "dict_type", "Input should be a valid dictionary")
        return errors
    if not isinstance(body.get("store_key"), str):
        bad(["store_key"], "string_type", "Input should be a valid string")
    brands = body.get("brand_names")
    if not isinstance(brands, list) or not brands:
        bad(["brand_names"], "too_short", "List should have at least 1 item")
    products = body.get("products")
    if not isinstance(products, list) or not products:
        bad(["products"], "too_short", "List should have at least 1 item")
        return errors
    for i, p in enumerate(products):
        for field in ("sku", "title"):
            if not isinstance(p.get(field), str):
                bad(["products", i, field], "string_type", "Input should be a valid string")
        if p.get("price") is not None and not isinstance(p.get("price"), (int, float)):
            bad(["products", i, "price"], "float_type", "Input should be a valid number")
        if not isinstance(p.get("attributes", {}), dict):
            bad(["products", i, "attributes"], "dict_type", "Input should be a valid dictionary")
    return errors


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", default="0.0.0.0")
    parser.add_argument("--port", type=int, default=8895)
    parser.add_argument("--store-cap", type=int, default=1)
    args = parser.parse_args()
    server = make_server((args.host, args.port), store_cap=args.store_cap)
    print(f"mock engine on http://{args.host}:{args.port} (store cap {args.store_cap})")
    server.serve_forever()
```

`dev/mock-engine/README.md`:

```markdown
# Mock engine

`python3 dev/mock-engine/mock_engine.py --port 8895 --store-cap 1`

Implements the connector API the module uses, from the frozen contract in
`Test/Fixtures`. Each API key is its own fake account. Switch failure modes:

    curl -s -X POST localhost:8895/__mode -d '{"mode":"store-limit"}'

Modes: normal, cold-504, slow (35 s), throttle-429 (Retry-After 60),
invalid-key, store-limit, product-limit, ownership, run-in-progress,
missing-code, purging. Received catalogs land in `received/` (git-ignored).
Purges are not simulated.

Tests: `cd dev/mock-engine && python3 -m unittest test_mock_engine.py -v`
```

- [ ] **Step 4: Run the mock-engine tests**

Run: `cd dev/mock-engine && python3 -m unittest test_mock_engine.py -v; cd ../..`
Expected: all PASS.

- [ ] **Step 5: Bring up a local Magento and record exactly how**

Create a Magento 2.4.8 store on port 8894 in a folder OUTSIDE this repo (e.g. `/Volumes/Tukar/Code/answer-eng/magento-local`, never committed). Use markshust/docker-magento's one-line setup with Magento Open Source packages from the Mage-OS mirror, or Mage-OS's own distribution if that setup supports it more simply. **Check the current upstream instructions first** (`context7` or the project README), because the onboarding commands change between releases. Install sample data, then wire in the module as a Composer path repository:

```bash
# inside the Magento project root
composer config repositories.answereng path /Volumes/Tukar/Code/answer-eng/answereng-magento
composer require fullspec/module-answereng:@dev
bin/magento module:enable Fullspec_AnswerEng
bin/magento setup:upgrade
bin/magento cache:flush
```

Then set **Stores › Configuration › Catalog › AnswerEng › Engine URL** to the mock, reachable from the container: `http://host.docker.internal:8895`.

Verification gates (all must hold before Step 6):
- `bin/magento module:status Fullspec_AnswerEng` → enabled.
- `bin/magento setup:db:status` → up to date, and the four `answereng_*` tables exist.
- The AnswerEng section renders at default and store-view scope with no errors in `var/log/exception.log`.
- `bin/magento cron:run` runs `answereng_sync_dirty` (check `cron_schedule`).

Write `docs/dev-environment.md` with the exact commands that worked, the ports (storefront 8894, mock engine 8895, test MariaDB 3307), the MariaDB `docker run` from Task 1, and how to reset (`bin/magento module:uninstall Fullspec_AnswerEng --non-composer` is NOT used for path installs: use `composer remove` + `bin/magento setup:upgrade`).

- [ ] **Step 6: Execute the end-to-end checklist and record it**

Create `docs/integration-checklist.md` from the list below. Run every item against the local Magento and the mock engine, and fill in the Result column (✅ / ❌ + note) and the date. Any ❌ is a bug: fix it in a new commit (with a test where the layer allows) before finishing.

```markdown
# Integration checklist — executed record

Environment: local Magento 2.4.8 (port 8894) + mock engine (port 8895). Date: ____

| # | Scenario | Steps | Expected | Result |
|---|---|---|---|---|
| 1 | Two views enabled | Enable views A and B with distinct keys; save | Both rows appear at default scope; both marked dirty | |
| 2 | Debounced push | Edit a product; wait 5 min; `bin/magento cron:run` | One push per view in `dev/mock-engine/received/` | |
| 3 | Product moves website | Move a product from A's website to B's | Both views resync; product only in B's payload | |
| 4 | Last product removed | Disable every product in A | Mock receives DELETE /stores/A/catalog; panel says catalog cleared | |
| 5 | Product back | Re-enable one product | Next push restores the catalog | |
| 6 | Mass update / import | Mass attribute update; CSV import | Every enabled view dirty, then pushed | |
| 7 | Over 5,000 | Generate 5,001 eligible products in A | blocked (too_many_products), no request | |
| 8 | Duplicate key | Give B the same key as A | Config save refused with the store-view name | |
| 9 | Store cap | Mock --store-cap 1, enable a second view | paused store_limit (not invalid_key); message names the options | |
| 10 | Each 403 class | modes invalid-key, product-limit, ownership, missing-code | invalid_key / product_limit / store_owned_elsewhere / unexpected | |
| 11 | 504 then exhaustion | mode cold-504; run cron 3× (advance next_attempt_at) | retrying → retrying → exhausted; nightly retries | |
| 12 | 429 | mode throttle-429; Sync now twice | second Sync now refused until Retry-After passes | |
| 13 | Unpause on config save | From paused invalid_key, save the API key | state ok, dirty, next cron pushes | |
| 14 | Snapshot identity | View results, change API key to a second key, stop the mock | panel shows "Results unavailable", never the old numbers | |
| 15 | Config change mid-push | mode slow; Sync now; change brands during the push | log shows superseded; next cron pushes new brands | |
| 16 | Test connection | Before first sync; after first sync | "first sync needed (uses 1 plan store)"; then found/waiting | |
| 17 | Danger zone ACL | Role without delete_store | Danger zone hidden; direct POST to answereng/action/delete denied | |
| 18 | Delete flow | Type key + tick box; Delete | button disabled until both; mock pending_deletion; view disabled; Cancel banner | |
| 19 | Server re-validation | POST delete with a wrong confirm_key (devtools) | refused; nothing sent | |
| 20 | Cancel | Cancel deletion | mock active; view stays disabled until re-enabled | |
| 21 | Key change keeps old store | Change A's key, sync | old key listed as previous; deleting it leaves A syncing | |
| 22 | Connection mismatch | Switch to a second API key | clear/delete/cancel refused with the connection message | |
| 23 | Forget | Forget a previous store | removed locally; mock receives nothing | |
| 24 | Shared key across views | Disable A; enable B with A's key; try clear/delete from A | refused: active for B | |
| 25 | CLI | `bin/magento answereng:sync --store=<code>`; unknown code | per-view messages; unknown code exits non-zero | |
| 26 | Clean uninstall | `composer remove` + `bin/magento module:uninstall` route per dev-environment.md | tables and answereng/* config rows gone | |
```

- [ ] **Step 7: Release packaging**

`bin/build-zip.sh`:

```bash
#!/usr/bin/env bash
# Build a Composer-installable zip of the module (no tests/dev/docs: see .gitattributes).
# Usage: bin/build-zip.sh 0.1.0
set -euo pipefail
version="${1:?usage: bin/build-zip.sh <version>}"
root="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$root/dist"
out="$root/dist/answereng-magento-$version.zip"
git -C "$root" archive --format=zip -o "$out" HEAD
echo "$out"
```

```bash
chmod +x bin/build-zip.sh
bin/build-zip.sh 0.1.0
unzip -l dist/answereng-magento-0.1.0.zip | head -40
```

Expected: the zip contains `composer.json`, `registration.php`, `etc/`, `Model/`, `Block/`, `Controller/`, `Cron/`, `Console/`, `Observer/`, `Setup/`, `view/`, and NOT `Test/`, `dev/`, `docs/`, `.github/` or `bin/`.

- [ ] **Step 8: Update the README**

Append to `README.md`:

```markdown
## Install (pilot merchants)

    composer config repositories.answereng artifact /path/to/folder-with-the-zip
    composer require fullspec/module-answereng
    bin/magento module:enable Fullspec_AnswerEng && bin/magento setup:upgrade

Then Stores › Configuration › Catalog › AnswerEng: paste the API key, enable
each store view you want analysed, and set brand names.

## Behaviour notes

- Each enabled store view is its own AnswerEng store (key defaults to the
  view's domain, saved once, unique across views).
- Category = the url_key of the deepest assigned category under the view's
  root (Magento has no ordered "first" category path; ties → lowest id).
- Price = the NOT LOGGED IN "from" price from the price index (null if not
  indexed). Description = short description, falling back to description.
- Store deletion has a 7-day undo window and needs the
  `Fullspec_AnswerEng::delete_store` permission. Roles with "Resource Access:
  All" include it; give limited roles only what they need.

## Local end-to-end

See docs/dev-environment.md (local Magento on 8894 + `dev/mock-engine` on 8895)
and the executed record in docs/integration-checklist.md.
```

- [ ] **Step 9: Commit**

```bash
git add dev/mock-engine/mock_engine.py dev/mock-engine/test_mock_engine.py dev/mock-engine/README.md docs/dev-environment.md docs/integration-checklist.md bin/build-zip.sh README.md
git commit -m "chore: mock engine, local Magento guide, executed end-to-end checklist, release zip" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## After the plan

- Push the repo and open a PR (or merge to `main`, per the owner). Do not publish a release.
- After engine PR #17 is deployed (per its runbook), run spec §9's live smoke test with a test key: push, Test connection, clear, push again, then delete + restore on a throwaway store key — never letting it reach purge. Owner-confirmed.
- Then tag `v0.1.0` and attach `dist/answereng-magento-0.1.0.zip` to a GitHub release (owner go-ahead).
