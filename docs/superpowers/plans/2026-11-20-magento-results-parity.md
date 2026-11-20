# Magento Results Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Magento status page for a store view shows everything the WooCommerce results page shows (visibility with change, trend, per-engine, share of voice, drafted fixes with copy and mark-as-applied), plus the WooCommerce settings extras (data disclosure, onboarding, auto-sync).

**Architecture:** A new `ResultsProvider` loads summary, suggested fixes and applied fixes under one 20-second `ReadBudget`, with per-connection caching and a stale snapshot, and hands a `ResultsView` to `PanelViewModel`. The server-rendered template is split into partials in the existing panel look. Mark/undo go through a new `FixActions` model and a POST controller behind a new ACL resource.

**Tech Stack:** PHP 8.1+, Magento 2.4.x module `Fullspec_AnswerEng`, PHPUnit (Unit + Db suites, MariaDB on 3307), Python mock engine (`dev/mock-engine`), RequireJS `panel-actions.js`.

**Spec:** `docs/superpowers/specs/2026-11-20-magento-results-parity-design.md` (approved). Read it before Task 1. WooCommerce reference: `../answereng-woocommerce/admin/class-results-page.php` and `../answereng-woocommerce/includes/class-settings.php`.

**Branch:** `feat/results-parity` (stacked on `feat/status-page`, PR #4).

## Global Constraints

- Core logic in plain PHP behind ports (Transport, SqlExecutor, SettingsProvider, Clock, KeyValueCache); Magento adapters stay thin.
- Branch only on the engine's `code` (and HTTP status), never its `error` text. Never log the API key or raw response bodies.
- `Test/Fixtures` is copied byte-for-byte from the engine with `CONTRACT.sha256`; never hand-edited.
- SQL timestamps come from `Clock`, never `NOW()`.
- Every new admin action: `HttpPostActionInterface` (via `AbstractAction`), form key, `ADMIN_RESOURCE`.
- Native Magento admin controls and type; brand colors only `#294c3b` (green) and `#e7edb6` (lime); existing tokens in `panel.css`.
- All engine-supplied text (fix content, reasons, prompt text, competitor names, engine keys) is escaped with `$escaper` at output.
- Page reads share one budget: **20 s total; each attempt ≤ 15 s and ≤ remaining; retry only if ≥ 5 s remain; a transient failure (no response, timeout, 5xx, 429) stops the remaining reads.**
- Unconfirmed mark/undo copy, exactly: "Couldn't confirm that change. AnswerEng didn't respond in time. Refresh in a moment to see whether it was applied."
- Out of scope: sign-in handoff, product links on fixes, "Rate it", charts on the overview, engine/dashboard changes.
- Checks before each commit: `vendor/bin/phpunit --testsuite Unit`; Db suite with `ANSWERENG_TEST_DB_DSN='mysql:host=127.0.0.1;port=3307;dbname=answereng_test' ANSWERENG_TEST_DB_USER=root ANSWERENG_TEST_DB_PASSWORD=root vendor/bin/phpunit --testsuite Db`; `vendor/bin/phpstan analyse` clean; `vendor/bin/phpcs` 0 errors (warnings may only grow by accepted static-method warnings in `PanelLabels`/`TrendChart`). Mock tests: `cd dev/mock-engine && uv run python3 -m unittest test_mock_engine.py`.

## Review Focus

1. **Engine text containing HTML** (`<script>`, `"`, `&`) in fix content, reasons, prompt text or competitor names must render as text, and the Copy draft button must copy the original text. Test: Task 8 template test.
2. **Malformed fix entries** (missing `reasons`, `reasons` not a list, unknown `kind`/`priority`, non-int `fix_id`, a 200 body that isn't a list) must be skipped or defaulted, never fatal. Test: Task 4 `FixViewTest`.
3. **Trend edge cases**: 0 or 1 points (footnote, no chart), identical points, `low == high`, rates of 0 and 1. Test: Task 8 `TrendChartTest`.
4. **Engine 404 on mark/undo uses code `unknown_store`** (the engine reuses it for an unknown fix). It must be read as "that fix no longer exists", never as the store being gone or "Out of sync". Test: Task 5 `FixActionsTest`.
5. **Store view switched or API key changed between page load and Mark**: caches are per connection fingerprint, so the action must forget the lists for the *current* fingerprint and never show another connection's fixes. Test: Task 4 `ResultsProviderTest::testFixesCacheIsPerConnection`.

---

### Task 1: Refresh the frozen engine contract

**Files:**
- Modify: `Test/Fixtures/**` (copy), `Test/Fixtures/CONTRACT.sha256`
- Test: existing `Test/Unit/ContractTest.php`, any test the new fixtures break

**Interfaces:**
- Produces: `Test/Fixtures/read_api/fix_applied.json`; `fixes.json` entries now carry `prompt_id` and `applied_at`; summary fixtures as on engine `origin/main` (973b3c5).

- [ ] **Step 1: Copy** every file under `tests/fixtures/` that is listed in the engine's `CONTRACT.sha256` from `git -C ../answereng-engine show origin/main:tests/fixtures/<path>` into `Test/Fixtures/<path>`, plus the manifest itself. Keep `error_codes.json` (it's in the manifest).
- [ ] **Step 2: Run** both PHPUnit suites. Expected: ContractTest passes; fix any other failure by updating the *test or code* to the new contract, never the fixture.
- [ ] **Step 3: Run** the mock tests (they read the fixtures). Expected: PASS.
- [ ] **Step 4: Commit** `test: sync the engine contract fixtures to engine main (973b3c5)`.

### Task 2: Mock engine serves fixes, applied state and a lost-response mode

**Files:**
- Modify: `dev/mock-engine/mock_engine.py`, `dev/mock-engine/README.md`
- Test: `dev/mock-engine/test_mock_engine.py`

**Interfaces:**
- Produces (HTTP, same auth/ownership rules as `/summary`):
  - `GET /stores/{key}/fixes?status=suggested|applied` (default `suggested`; other values in `("suggested","approved","rejected","refused","applied")` return `[]`; anything else → 422 `invalid_request`). Suggested = entries of `read_api/fixes.json` whose `fix_id` is not in the store's applied set; applied = those that are, with `applied_at` set.
  - `POST|DELETE /stores/{key}/fixes/{fix_id}/applied` → 200 with the fix (shape of `fix_applied.json`; `applied_at` null after DELETE); unknown fix → 404 `unknown_store`.
  - Store record gains `"applied": {fix_id: iso_timestamp}`, saved in the state file.
  - New mode `lost-response`: a POST/DELETE `…/applied` applies the change, saves state, then closes the connection without a response.

- [ ] **Step 1: Write failing tests** `test_fixes_suggested_then_applied_then_undo` (push a store; GET suggested has 2; POST 118 → 200 with `applied_at`; suggested has 1, applied has [118]; DELETE 118 → 200 `applied_at` null; suggested has 2), `test_unknown_fix_is_404_unknown_store`, `test_lost_response_still_applies` (set mode; POST 118 raises `http.client.RemoteDisconnected` or similar; set mode normal; applied has [118]), `test_applied_survives_restart` (in `PersistenceTest`).
- [ ] **Step 2: Run** mock tests. Expected: the four new tests FAIL.
- [ ] **Step 3: Implement** in `mock_engine.py`; add `lost-response` to `MODES`; document routes and the mode in the README.
- [ ] **Step 4: Run** mock tests (three times). Expected: PASS.
- [ ] **Step 5: Commit** `dev: mock engine serves fixes and applied state`.

### Task 3: Read budget and fix endpoints in EngineClient

**Files:**
- Create: `Model/Engine/ReadBudget.php`
- Modify: `Model/Engine/EngineClient.php`, `Test/Unit/Engine/FakeTransport.php` (`onSend` also receives `int $timeoutSeconds` as a third argument)
- Test: `Test/Unit/Engine/ReadBudgetTest.php`, `Test/Unit/Engine/EngineClientTest.php` (extend)

**Interfaces:**
- Produces:
  - `final class ReadBudget { public const TOTAL = 20; public const ATTEMPT_MAX = 15; public const RETRY_MIN = 5; public function __construct(Clock $clock, int $totalSeconds = self::TOTAL); public function remaining(): int; public function attemptTimeout(): int /* min(ATTEMPT_MAX, remaining) */; public function allowsRetry(): bool /* remaining >= RETRY_MIN */; public function stop(): void; public function stopped(): bool /* stop() called or remaining <= 0 */ }`
  - `EngineClient::summary(Connection $c, string $storeKey, ?ReadBudget $budget = null): EngineResult` (null = today's behavior)
  - `EngineClient::fixes(Connection $c, string $storeKey, string $status, ?ReadBudget $budget = null): EngineResult` → `GET /stores/{key}/fixes?status={status}`
  - `EngineClient::markApplied(Connection $c, string $storeKey, int $fixId): EngineResult` → POST, `EngineClient::unmarkApplied(...)` → DELETE, both `…/fixes/{id}/applied`, sent once with the write timeout.
  - `EngineResult::isTransient(): bool` → status null, ≥ 500, or 429.
  - With a budget, `read()`: if `stopped()` return `EngineResult::transportFailure('read budget exhausted')` without sending; attempt with `attemptTimeout()`; retry only on transient and `allowsRetry()`; if the final result is transient call `stop()`.

- [ ] **Step 1: Write failing tests**: `ReadBudgetTest::testAttemptTimeoutShrinksWithTheBudget` (FixedClock; at t=0 → 15; advance 8 → 12; advance 8 → 4, `allowsRetry()` false; advance 4 → `stopped()` true); `EngineClientTest::testHangingEngineStopsWithinTheBudget` (`onSend` advances the FixedClock by the timeout and the queue holds `TransportException`s; summary then fixes then fixes: requests made = 2 (15 s + 5 s), total timeouts ≤ 20, third call sends nothing and returns transient); `testRetryOnlyWhenFiveSecondsRemain`; `testFixesPathAndStatus` (URL ends `/stores/shop.example.com/fixes?status=applied`); `testMarkAndUnmarkAppliedAreSingleWrites` (POST/DELETE, URL `/fixes/118/applied`, one request each even on 503).
- [ ] **Step 2: Run** Unit suite. Expected: new tests FAIL.
- [ ] **Step 3: Implement** `ReadBudget`, the client methods and `isTransient()`.
- [ ] **Step 4: Run** Unit suite. Expected: PASS.
- [ ] **Step 5: Commit** `feat: read budget and fix endpoints in the engine client`.

### Task 4: Results loading, caching and snapshot

**Files:**
- Create: `Model/Admin/FixView.php`, `Model/Admin/ResultsView.php`, `Model/Admin/ResultsProvider.php`
- Modify: `Model/Admin/SummaryProvider.php` (accept a budget; expose whether it hit a transient failure), `Model/Sync/SyncStateRepository.php`, `Model/Sync/SyncState.php`, `etc/db_schema.xml`, `etc/db_schema_whitelist.json`, `Controller/Adminhtml/Action/RefreshSummary.php`, `etc/di.xml` if needed
- Test: `Test/Unit/Admin/FixViewTest.php`, `Test/Db/ResultsProviderTest.php`, `Test/Db/SummaryProviderTest.php`, `Test/Db/SchemaTest.php`

**Interfaces:**
- Consumes: Task 3 `ReadBudget`, `EngineClient::fixes`, `EngineResult::isTransient`.
- Produces:
  - `final class FixView { public function __construct(public readonly int $fixId, public readonly string $kind, public readonly string $content, public readonly string $priority, public readonly array $reasons /* list<string> */, public readonly string $promptText, public readonly string $engine, public readonly ?string $appliedAt); /** @return list<FixView> */ public static function listFromJson(mixed $json): array }` — skips entries without an int `fix_id` or a string `content`; defaults `kind`/`priority`/`engine` to `''`, `reasons` to the string items of a list or `[]`; a non-list returns `[]`.
  - `final class ResultsView { public function __construct(public readonly SummaryView $summary, public readonly ?array $fixes /* list<FixView>|null = unavailable */, public readonly ?array $applied /* null = hide section */, public readonly bool $fixesStale) }`
  - `ResultsProvider::forView(int $storeId): ResultsView`; `ResultsProvider::forget(int $storeId): void` (summary + both fix lists); `ResultsProvider::forgetFixes(int $storeId): void` (both fix lists only).
  - `SummaryProvider::forView(int $storeId, ?ReadBudget $budget = null): SummaryView` (unchanged result; marks the budget stopped on transient).
  - Cache keys: `answereng_fixes_suggested_{fingerprint}`, `answereng_fixes_applied_{fingerprint}`; TTLs `SummaryProvider::CACHE_SECONDS` / `NO_RUN_CACHE_SECONDS` by the same rule as the summary.
  - Schema: `snapshot_json` becomes `mediumtext`; new nullable `mediumtext` columns `snapshot_fixes_json`, `snapshot_applied_json` (whitelisted). `SyncStateRepository::saveFixesSnapshot(int $storeId, string $fingerprint, string $fixesJson, ?string $appliedJson): void` (only when `snapshot_fingerprint` equals `$fingerprint`); `clearSnapshot()` also nulls both; `SyncState` gains `?string $snapshotFixesJson`, `?string $snapshotAppliedJson`.
  - Rules: fixes are fetched only when the summary is `FRESH` with a completed run. When the summary is `STALE`, fixes/applied come from the snapshot (if its fingerprint matches) with `fixesStale = true`. Transient failure on suggested → snapshot fixes, `fixesStale = true`, applied not fetched. Applied failure → `applied = null`. All other summary states → `fixes = null`, `applied = null`. Both lists fetched OK → `saveFixesSnapshot`.
  - `RefreshSummary::perform()` calls `ResultsProvider::forget()` (message unchanged).

- [ ] **Step 1: Write failing tests**:
  - `FixViewTest::testParsesTheContractFixture` (two fixes from `read_api/fixes.json`, first is 118/copy/high with 2 reasons) and `testMalformedEntriesAreSkippedOrDefaulted` (Review Focus 2: a non-list, a missing `fix_id`, `reasons: "x"`, unknown `kind` kept as given).
  - `ResultsProviderTest`: `testFreshSummaryLoadsBothListsOnceAndCachesThem` (three requests the first time, none the second); `testHangingEngineFallsBackToTheSnapshotWithinTheBudget` (seed a snapshot; transport throws `TransportException` and `onSend` advances the clock by the timeout; result has `STALE` summary, snapshot fixes, `fixesStale` true, total timeouts ≤ 20); `testAppliedFailureHidesOnlyTheAppliedSection`; `testNoRunMeansNoFixRequests`; `testFixesCacheIsPerConnection` (Review Focus 5: change the API key in FakeSettings → new requests, no old lists); `testForgetFixesKeepsTheSummary`.
  - `SchemaTest`: the two new columns exist and `snapshot_json` is `mediumtext`.
- [ ] **Step 2: Run** both suites. Expected: new tests FAIL.
- [ ] **Step 3: Implement** the classes, schema, repository and wiring.
- [ ] **Step 4: Run** both suites, phpstan, phpcs. Expected: PASS / clean.
- [ ] **Step 5: Commit** `feat: load fixes with the summary under one read budget`.

### Task 5: Mark and undo applied fixes

**Files:**
- Create: `Model/Admin/FixActions.php`, `Controller/Adminhtml/Action/FixApplied.php`
- Modify: `etc/acl.xml`, `Test/Unit/AdminXmlTest.php`, `dev/e2e/acl-users.php` (aesync gets `::fixes`; aeconfig doesn't)
- Test: `Test/Db/FixActionsTest.php` (or Unit with fakes, following `RemoteStoreActionsTest`)

**Interfaces:**
- Consumes: Task 3 `markApplied`/`unmarkApplied`, Task 4 `ResultsProvider::forgetFixes`.
- Produces:
  - ACL `Fullspec_AnswerEng::fixes`, title "Mark drafted fixes applied", child of `Fullspec_AnswerEng::status` (children are now `::sync`, `::delete_store`, `::fixes`).
  - `FixActions::setApplied(int $storeId, int $fixId, bool $applied): ActionResult`:
    - 200 → `forgetFixes`; ok, message "Marked as applied." / "Moved back to drafted fixes."
    - 404 (any code, incl. `unknown_store`) → not ok, "That fix no longer exists in AnswerEng. Refresh to see the current list." Caches kept.
    - 409 → not ok, "AnswerEng refused that fix, so it can't be marked as applied." Caches kept.
    - other 4xx → not ok, "AnswerEng didn't accept that change. Nothing was changed." Caches kept.
    - transient (no response, timeout, 5xx) → `forgetFixes`; not ok, the unconfirmed copy from Global Constraints.
    - store view disabled or not configured → not ok, "Enable this store view and enter an API key first."
  - `FixApplied` controller: `ADMIN_RESOURCE = 'Fullspec_AnswerEng::fixes'`; params `store`, `fix_id` (int > 0, else error "Unknown fix."), `op` (`mark`|`unmark`); returns `back()`.

- [ ] **Step 1: Write failing tests**: `FixActionsTest::testSuccessForgetsBothListsOnly`, `testEngine404IsAMissingFixNotAMissingStore` (Review Focus 4: 404 `unknown_store` → the fix message; `SummaryProvider` cache untouched; sync state untouched), `testRefusedIsUnchanged`, `testLostResponseForgetsListsAndSaysUnconfirmed` (TransportException → exact unconfirmed copy; both caches dropped), `testFiveHundredIsUnconfirmed`; `AdminXmlTest`: `::fixes` under `::status`, `FixApplied::ADMIN_RESOURCE` is `::fixes`.
- [ ] **Step 2: Run** suites. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** suites, phpstan, phpcs. Expected: PASS / clean.
- [ ] **Step 5: Commit** `feat: mark drafted fixes applied from Magento`.

### Task 6: Auto-sync switch

**Files:**
- Modify: `etc/adminhtml/system.xml` (new group `sync`, label "Catalog sync", field `auto_sync`, Yes/No, showInDefault/Website/Store = 1, comment "Syncs your catalog when products or settings change. Turn off to sync only nightly or when you click Sync now."), `etc/config.xml` (`answereng/sync/auto_sync` = 1), `Model/Config/Settings.php` (add `public readonly bool $autoSync = true` as the last constructor parameter; not part of `hash()` or `fingerprint()`), `Model/Config/ConfigSettingsProvider.php` (`PATH_AUTO_SYNC = 'answereng/sync/auto_sync'`), `Test/Support/FakeSettings.php` (`$o['autoSync'] ?? true`), `Model/Sync/SyncStateRepository.php`, `Cron/SyncDirty.php`
- Test: `Test/Db/SyncStateRepositoryTest.php`, `Test/Db/CronTest.php`

Note: the spec names `answereng/store/auto_sync`, but the `store` group is store-view-only; the spec also requires default/website scope, so the field lives in its own group at `answereng/sync/auto_sync`.

**Interfaces:**
- Produces: `SyncStateRepository::selectForDebounce(array $enabledStoreIds, \DateTimeImmutable $now, array $manualStoreIds = []): array` — for ids in `$manualStoreIds` only the `state = 'retrying' AND next_attempt_at <= ?` branch applies. `SyncDirty` passes the enabled views whose `forStore()->autoSync` is false. `answereng_reconcile` unchanged.

- [ ] **Step 1: Write failing tests**: `SyncStateRepositoryTest::testManualViewsOnlyRetry` (view 1 dirty+quiet and view 2 retrying-due, both manual → only 2; view 1 auto → 1 and 2); `CronTest::testAutoSyncOffSkipsDirtyViewsButReconcileStillRuns`.
- [ ] **Step 2: Run** Db suite. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** both suites, phpstan, phpcs. Expected: PASS / clean.
- [ ] **Step 5: Commit** `feat: auto-sync switch (nightly and manual syncs keep running)`.

### Task 7: Settings page — data disclosure and onboarding

**Files:**
- Create: `Block/Adminhtml/System/Config/DataDisclosure.php` (Field frontend model, `<td colspan="4">` like `StatusLink`), `view/adminhtml/templates/data-disclosure.phtml`
- Modify: `etc/adminhtml/system.xml` (group `data`, label "What data leaves this site", collapsed by default, field rendered by `DataDisclosure`; `api_key` comment), `view/adminhtml/web/css/panel.css`
- Test: `Test/Unit/AdminXmlTest.php` (the group exists and uses the block)

**Interfaces:**
- Produces:
  - Disclosure, two lists. **Sent to AnswerEng:** "Product SKU, name and short description (or the full description, cleaned, when there is no short description)", "Price, category and the attributes you choose", "Your brand and competitor names". **Never sent, anywhere:** "Customer names, emails and addresses", "Orders and payment data", "Product images, analytics and telemetry". Then: "Product data goes to %1, the engine URL configured above." with the effective engine URL (`SettingsProvider::forStore(0)->engineUrl`). Verify each "sent" line against `Model/Catalog/CatalogBuilder.php` before committing.
  - `api_key` comment (exact): "Paste the API key from your AnswerEng welcome email. No API key yet? Get one at answereng.io. The free tier needs no card, and your key arrives by email." with "answereng.io" linked to `https://answereng.io`.

- [ ] **Step 1: Write the failing `AdminXmlTest` assertions.** Run: FAIL.
- [ ] **Step 2: Implement.**
- [ ] **Step 3: Run** Unit suite, phpcs, then check the config page on the local stack (`bash ../magento-local/scripts/refresh-module.sh`, bump `deployed_version.txt` with `printf "%s"`, `cache:flush`): the group renders collapsed, opens, and shows the local engine URL. Expected: PASS, no console errors.
- [ ] **Step 4: Commit** `feat: data disclosure and onboarding on the settings page`.

### Task 8: The results page

**Files:**
- Create: `Model/Admin/TrendChart.php`, `view/adminhtml/templates/status/alerts.phtml`, `status/visibility.phtml`, `status/breakdown.phtml`, `status/fixes.phtml`, `status/sync.phtml`
- Modify: `Model/Admin/PanelViewModel.php`, `Model/Admin/PanelLabels.php`, `Block/Adminhtml/Status/Panel.php` (memoize `getPanel()` per request; add `renderPart(string $name): string` that returns `fetchView(getTemplateFile('Fullspec_AnswerEng::status/' . $name . '.phtml'))`), `view/adminhtml/templates/status.phtml`, `view/adminhtml/web/css/panel.css`, `view/adminhtml/web/js/panel-actions.js`
- Test: `Test/Unit/Admin/TrendChartTest.php`, `Test/Unit/Admin/PanelLabelsTest.php`, `Test/Db/PanelViewModelTest.php`, `Test/Unit/Admin/StatusTemplateTest.php` (renders the partials with a stub block and asserts on the HTML)

**Interfaces:**
- Consumes: Task 4 `ResultsProvider`/`ResultsView`/`FixView`, Task 5 ACL `::fixes` and route `answereng/action/fixapplied`, Task 6 `Settings::$autoSync`.
- Produces:
  - `PanelViewModel::forView()` replaces `'summary'` with `'results' => ResultsView` and adds `'canApplyFixes' => isAllowed('Fullspec_AnswerEng::fixes')`, `'autoSync' => bool`, `'healthy' => bool` (pill tone is `good` and results exist), `'urls']['fixApplied']`. Keep `$panel['results']->summary` usable wherever `$panel['summary']` was read.
  - `PanelLabels::engine(string $key): array{label: string, color: string}` — WooCommerce `ENGINE_META` and fallback, plus `bedrock` alone → "Amazon Bedrock". `PanelLabels::delta(array $trend): ?float` — points between the last two trend rates (null with < 2 points); |delta| < 0.05 renders "no change vs last run".
  - `TrendChart::geometry(array $points, int $width, int $height): ?array{line: string, band: string, labels: list<array{x: float, text: string}>}` — null with fewer than 2 valid points; rates clamped to 0..1.
  - Page order and behavior exactly as the spec's "Decided (by the owner)" list, with "Alerts stay on top" and "Open full results" from "Decided by Claude". Jump link "%1 drafted fixes to review →" (singular "1 drafted fix to review →") targets `#answereng-fixes`. Sections use `<details>`; Sync opens when `!healthy`. Copy draft: `data-answereng-copy` with the draft text in an attribute; "Copied" for 2 s; fallback selects the `<pre>` text. Mark/Undo buttons only when `canApplyFixes`, posting `store`, `fix_id`, `op` through the existing `data-answereng-action` mechanism. Stale banner covers fixes when `fixesStale`. Auto-sync off line in Sync: "Auto-sync is off. Your catalog syncs nightly, or when you click Sync now."
  - Copy for headings, footnotes, intro and empty states: WooCommerce's `render_hero`, `render_trend`, `render_engine_breakdown`, `render_share_of_voice`, `render_fixes`, `render_applied_list`, word for word except: drop any "WordPress"/"WooCommerce" wording, and the "Edit competitor list" link goes to the Magento settings page (Brand group).

- [ ] **Step 1: Write failing tests**: `TrendChartTest` (Review Focus 3: 0 and 1 points → null; equal points; `low == high`; rates 0 and 1 stay inside the box); `PanelLabelsTest::testEngineLabels` (`perplexity:sonar` → Perplexity, the bedrock Claude key → Claude, `bedrock` → Amazon Bedrock, unknown → itself) and `testDelta`; `PanelViewModelTest` new keys; `StatusTemplateTest::testEngineTextIsEscaped` (Review Focus 1: fix content `<script>alert(1)</script>&"` appears escaped in the HTML and intact in `data-answereng-copy` after HTML-decoding), `testFixesAreAboveSyncAndJumpLinkCountsThem`, `testMarkButtonsNeedThePermission`, `testSyncOpensWhenUnhealthy`.
- [ ] **Step 2: Run** suites. Expected: FAIL.
- [ ] **Step 3: Implement** model pieces, then the partials, CSS and JS.
- [ ] **Step 4: Run** suites, phpstan, phpcs. Expected: PASS / clean.
- [ ] **Step 5: Local check** (refresh module, bump static version with `printf "%s"`, `cache:flush`, mock running with state): store view 1 shows all five parts in order at 1280 px and 390 px; Copy draft copies; Mark → fix moves to Applied, Undo moves it back; mock `slow` mode → page returns within ~25 s with the stale banner over results and fixes; mock `lost-response` → unconfirmed message, then after `normal` the fix shows as applied; a role without `::fixes` sees no Mark/Undo; no console errors.
- [ ] **Step 6: Commit** `feat: full results on the Magento status page`.

### Task 9: Docs, checklist and end-to-end rows

**Files:**
- Modify: `docs/2026-10-05-magento-connector-design.md` (§7 admin surface, §11 endpoints used), `README.md`, `docs/integration-checklist.md`, `dev/e2e/admin.py` / `row28.py` (FixApplied: GET 404, missing/bad form key refused, aeconfig refused)

- [ ] **Step 1: Update** the docs to describe the page as built (sections, budget, mark/undo outcomes, auto-sync, disclosure) and add checklist rows with results from Task 8 Step 5.
- [ ] **Step 2: Run** the e2e checks for `fixapplied` against the local stack. Expected: GET 404; no/bad form key → redirect to dashboard; aeconfig → denied.
- [ ] **Step 3: Run** `bin/magento setup:di:compile` in the local stack. Expected: success.
- [ ] **Step 4: Commit** `docs: results parity on the Magento status page`.
