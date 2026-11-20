# Magento Connector (+ engine error codes, catalog clear, store deletion) — Design

**Date:** 2026-10-05
**Status:** Approved design, pre-implementation
**Linear:** ENG-205 (project AnswerEng)
**Context:** Second catalog connector for the AnswerEng engine, after
WooCommerce (`2026-07-08-woocommerce-connector-design.md`). Same frozen
catalog contract, same hosted engine, adapted to Magento's multi-store model.
Two workstreams: **A — engine** (machine-readable error codes, catalog clear,
scheduled store deletion; §11) and **B — the Magento module** (§2–§10).

## 1. Goals and constraints

- **Distribution (v1): direct.** Private Composer package / GitHub release zip
  for pilot merchants. Built to Adobe Commerce Marketplace (EQP) quality from
  the first commit, but not submitted in v1.
- **Admin surface: sync + status panel.** Settings stay in Stores ›
  Configuration › Catalog › AnswerEng. Sync status/log, "Sync now", "Test
  connection" and ONE headline visibility number with its interval live on
  Catalog › AnswerEng › Status & AI Visibility (moved there 2026-11-05:
  configuration holds settings, the page holds what merchants check), linking
  to the dashboard (`https://app.answereng.io/stores/{key}`) for everything
  else. No native results page (the dashboard, which accepts API-key sign-in,
  owns results).
- **Additive engine changes (§11):** a stable `code` field on every error
  response; `DELETE /stores/{store_key}/catalog` (clears a store's products;
  store, history and plan slot remain); and scheduled, cancellable store
  deletion (`DELETE /stores/{store_key}` with a 7-day undo window, then a
  permanent purge that frees the plan slot). Everything else
  stays as is: full-replace `POST /catalog` (≥ 1 and ≤ 5,000 products), plan
  store caps (free/starter 1, pro 3). WooCommerce is unaffected (additive
  fields, unchanged catalog contract). Engine `main` already carries
  undeployed PRs #13/#14, so the engine PR's deploy is sequenced by the owner
  (§9); the Magento release depends on it.
- **Local-first.** Development and integration testing run against a local
  Magento and a local mock engine that reproduces the engine's real responses.
  Live pushes to `api.answereng.io` happen only in the release smoke check
  (§9), each confirmed with the owner. (The WordPress.org deploy freeze that
  originally motivated this lifted 2026-10-05; the rule stays.)

## 2. Module identity

- Repo `Fullspec-Studio/answereng-magento` (private).
- Composer `fullspec/module-answereng`, type `magento2-module`, license
  `OSL-3.0` + `AFL-3.0`. Module `Fullspec_AnswerEng`.
- Supports Magento Open Source / Adobe Commerce 2.4.6–2.4.8, PHP 8.1–8.3.

```
registration.php, etc/module.xml, composer.json
etc/config.xml               defaults (engine URL blank = hosted; every view DISABLED until opted in)
etc/adminhtml/system.xml     Stores › Configuration › Catalog › AnswerEng
etc/crontab.xml              answereng_sync_dirty (*/5), answereng_reconcile (nightly)
etc/events.xml               product save/delete, mass-attribute update, import, category save
etc/di.xml                   CLI command, dedicated logger
etc/acl.xml                  Fullspec_AnswerEng::config, ::sync, ::delete_store
etc/db_schema.xml            answereng_sync_state, answereng_sync_log,
                             answereng_remote_store, answereng_remote_store_view
Model/Config.php             per-view config + effective hosted defaults
Model/CatalogBuilder.php     store view -> CatalogPush payload (frozen contract)
Model/EngineClient.php       HTTP wrapper, cold-engine retry, error mapping
Model/SyncState.php          dirty flag / timestamps / attempts / snapshot per view
Model/SyncLog.php            rolling 20 entries per view
Model/Syncer.php             lock -> guard -> build -> push -> record, one view
Cron/SyncDirty.php, Cron/Reconcile.php
Observer/MarkDirty.php
Console/Command/Sync.php     bin/magento answereng:sync [--store=<code>]
Block/Adminhtml/Status/Panel.php + template (status page; config shows a link)
Controller/Adminhtml/Sync/Now.php, Controller/Adminhtml/Connection/Test.php
Setup/Uninstall.php          drops tables + config rows on module:uninstall
tests/Unit/…
tests/fixtures/              catalog_push.json, read_api/*, CONTRACT.sha256 (copied verbatim)
dev/docker-compose.yml, dev/mock-engine/
docs/integration-checklist.md
```

## 3. Configuration

| Setting | Scope | Notes |
|---|---|---|
| Engine URL | default | Blank = hosted `https://api.answereng.io`, applied at request time, never persisted. |
| API key | default | `Magento\Config\Model\Config\Backend\Encrypted` (encrypted at rest). One workspace key for all views. Never logged. |
| Enabled for AnswerEng | store view only | Opt-in per view; default **No**. Not settable at default/website scope (no inheritance). |
| Store key | store view only | Persisted, never computed per request: when a view is saved enabled with a blank key, the backend model fills it with the host of the view's base URL AT THAT MOMENT and saves it. Later base-URL changes do not move it. |
| Brand names | default, website, store view | Inherited downward; one or more effective values required when enabled. |
| Competitors | default, website, store view | Inherited downward; zero or more. |
| Auto-sync | default, website, store view | `answereng/sync/auto_sync`, Yes/No, default Yes. When No, only change-triggered syncs (the debounced dirty-view pass) stop for that view; the nightly reconcile, retries of a failed sync, **Sync now** and the CLI still run. Not part of the settings hash or fingerprint. |

Engine URL and API key are default-scope only (`showInWebsite`/`showInStore`
= 0). The only settings with inheritance are brands and competitors, and the
invalidation rule (§6 Configuration saves) compares EFFECTIVE values per
view, so a default- or website-scope save invalidates exactly the descendant
views whose effective value changed.

Persisting the store key deliberately departs from WooCommerce's
request-time host default: a store key is an identity in the engine (a plan
slot plus its history), so it must never change as a side effect of an
unrelated setting such as the base URL.

**Store-key uniqueness:** store keys must be unique across enabled
views. Config save fails validation with "store key must be unique" when two
enabled views resolve to the same key; the syncer also refuses a view whose
key collides (defense in depth). Never auto-suffix.

**Local-looking store keys:** saving a store key that looks local
(`localhost`, an IP address, or a `.test`, `.local`, `.localhost`,
`.internal`, `.example`, `.invalid`, `.lan` or `home.arpa` name) shows a
warning — never an error — when the view is enabled and uses the production
engine: "Store key "{key}" looks like a local address. It doesn't identify
your shop, and AnswerEng refuses a key another account already uses. Set it
to your shop's public domain." (AnswerEng never visits the store; the key is
only the store's identity, so a generic key like `localhost` risks
`store_owned_elsewhere`.) Against a test engine a local key is normal, so no
warning.

**Changing a synced store key.** Each store key the engine has seen occupies
one of the workspace's plan store slots until it is deleted (§11.3: 7-day
undo window, then purge); catalog clear (§11.2) keeps the store and its
slot. Once a
view has synced, its Store key field comment reads
"This view has synced as `<key>`. Changing it registers a NEW store in
AnswerEng that counts against your plan; the old store keeps its data and
its slot until you delete it (Danger zone, §7.1, which keeps listing it)." Saving a different effective key for a previously synced view
also shows a post-save warning repeating that. (Magento system config has no
pre-save confirm without custom JS; the comment is the before-warning.)

## 4. Catalog building

`CatalogBuilder::build(int $storeId)` runs under store emulation.

**Scope:** status enabled, visibility in {Catalog, Search, Catalog+Search},
assigned to the view's website. Configurables/bundles/grouped are one product
each; their invisible children are excluded.

**Consistent export.** Step 1 captures the eligible entity-id SET in one
query (ordered by `entity_id`). The guards (below) run against that set's
size. Step 2 loads attributes for the captured ids in chunks of 500
(`entity_id IN (…)`) — never offset pagination. Products deleted between the
steps simply drop out; products added after step 1 are not included (their
save marked the view dirty, so the next push picks them up). Any chunk
failure aborts the whole export — no partial payload is ever sent, because
ingestion is full-replace and a partial catalog would become authoritative.
Before posting, the final product count is re-checked against both guards
(≥ 1, ≤ 5,000).

**Guards** (on the captured id set, re-checked on the final payload):
- **> 5,000** → the view refuses to sync (state `blocked`, reason
  `too_many_products`) with a persistent admin system message naming the
  limit.
- **0 eligible products** → no `POST /catalog` (the contract requires at
  least one product; `CatalogPush.products` has `min_length=1`). Instead the
  syncer calls `DELETE /stores/{key}/catalog` (§11) so the engine mirrors
  Magento: zero products. This is reversible — the store, its history and
  its slot remain, and the next push with ≥ 1 product restores the catalog.
  If the view has never synced, nothing is called. Outcome: state `ok`,
  reason `catalog_cleared`; the panel says "No eligible products — the
  AnswerEng catalog is cleared and weekly analysis is paused until products
  return." The clear call uses the same revision guard, classification and
  retry rules as a push (§6).

| Contract field | Source |
|---|---|
| `sku` | `sku` |
| `title` | store-view `name` |
| `description` | `short_description`, tags stripped, trimmed; falls back to `description` (tags stripped) when empty |
| `price` | `min_price` from `catalog_product_index_price` for the view's website and customer group 0 (NOT LOGGED IN) — the "from" price for composite products; `null` if not indexed |
| `category` | `url_key` of the deepest category on the product's first category path under the view's root category; `""` if none |
| `attributes` | attributes with `is_visible_on_front = 1`, keyed by **attribute code**, value = store-view frontend text; multiselect joined with `", "`; empty values skipped; `{}` (`new \stdClass()`) when none |

Payload key order and the empty-`{}` encoding are asserted against
`tests/fixtures/catalog_push.json`.

## 5. Change detection and scheduling

- **Product save:** `catalog_product_save_before` reads the product's
  PERSISTED website ids from the database (not the in-memory model) and
  stashes them on the product; `catalog_product_save_after` marks dirty the
  enabled views of the UNION of previous and current websites, so a website
  that loses the product is resynced too.
- **Product delete:** `catalog_product_delete_before` captures the website
  ids (the link rows are gone after delete); `catalog_product_delete_after`
  marks those websites' enabled views dirty.
- **Mass attribute update, mass website reassignment
  (`catalog_product_to_website_change`), import finish,
  `catalog_category_save_after`:** mark every enabled view dirty.
- Marking is one upsert per view (`dirty=1, last_change_at=now`), so bulk
  operations cost one row write per view, not per product.
- `answereng_sync_dirty` (every 5 min) and `answereng_reconcile` (nightly)
  select views by the state machine in §6; manual "Sync now" and
  `bin/magento answereng:sync [--store=<code>]` bypass selection for the
  named view(s).

## 6. Sync execution and errors

`Syncer::sync(int $storeId, string $reason)`:

1. Acquire per-view lock (`LockManagerInterface`); if held, skip (logged).
2. Read the view's `config_revision` and capture an immutable settings
   snapshot (effective engine URL, API key, store key, brands, competitors,
   enabled). Everything below uses only the snapshot. A disabled view stops
   here.
3. Record `push_started_at`; build (§4, guards included); `POST /catalog`.
4. Apply the outcome with ONE atomic update whose WHERE clause is the
   configuration revision ONLY: `UPDATE answereng_sync_state SET … WHERE
   store_id = ? AND config_revision = ?captured_revision`. Product edits
   never affect whether it matches. On success it always sets
   `last_sync_at`, product count, `synced_store_key`, state `ok`,
   `attempts` 0, `next_attempt_at` NULL, and sets the dirty flag inside the
   same statement with
   `dirty = CASE WHEN last_change_at < ?push_started_at THEN 0 ELSE dirty END`
   (an edit during the push stays dirty; never read-then-write). On failure
   it sets state/attempts per the classification table. If 0 rows match,
   the configuration really did change mid-push: the outcome is discarded,
   only a sync-log entry "superseded by configuration change" is written,
   and the view stays dirty for the new configuration.
5. **Remote side effects are recorded unconditionally.** Any 2xx push
   registers the remote store it touched (§7.1 registry) in its OWN
   statement, NOT guarded by the revision, using the snapshot's engine URL
   and store key plus the response's `X-AnswerEng-Account`. Its role for
   the view is then derived (§7.1), so a push superseded by a config change
   still leaves its store visible — as `previous` — in the Danger zone.
   Tested from both `ok`
   and `retrying` starting states, with and without a mid-push product edit.

**Configuration saves.** A save of the AnswerEng section that changes any
setting affecting a view (engine URL or API key at default scope → every
view; store key, brands, competitors, or enabled at a view's scope → that
view) runs, for each affected view, one update: `config_revision += 1`,
`dirty = 1`, `last_change_at = now`, `attempts = 0`, `next_attempt_at =
NULL`, state `ok` (clearing `paused`/`exhausted`). This queues the new
configuration's push and invalidates in-flight attempts.

### Sync states (per view, in `answereng_sync_state.state`)

| State | Meaning |
|---|---|
| `ok` | Last push succeeded (or never synced yet). |
| `retrying` | Transient failure; `attempts` 1–2, `next_attempt_at` set. |
| `exhausted` | 3 transient failures in a row. |
| `blocked` | Local guard refused: `too_many_products`, `duplicate_store_key`. |
| `paused` | Engine refused the push. `reason` holds the classified code: account/config-dependent (`invalid_key`, `store_limit`, `store_owned_elsewhere`, `store_pending_deletion`, `unexpected`) or catalog-dependent (`validation_failed`, `product_limit`). |

**Cron selection predicates:**
- `answereng_sync_dirty` selects enabled views matching EITHER
  (a) `state IN ('ok','blocked') AND dirty = 1 AND last_change_at <= now −
  5 min`, OR (b) `state = 'retrying' AND next_attempt_at <= now`
  (regardless of `dirty` — a failed manual or nightly push on a clean view
  still gets its retries). `exhausted` and `paused` are never selected by
  this job.
- `answereng_reconcile`: every enabled view whose state is `ok`, `blocked`
  or `exhausted`, plus `retrying` views whose `next_attempt_at <= now`.
  A `retrying` view still inside its delay is skipped; the five-minute job
  picks it up when due.
- **Manual "Sync now" / CLI** may override a transient retry delay (5xx,
  timeout) — that is an explicit merchant action — but NOT a server-imposed
  429 `Retry-After` window (`retry_reason = throttled`): during that window
  the button reports "Rate limited by AnswerEng — try again after <time>"
  and the CLI exits non-zero with the same message.

**What resumes a view:**
- `retrying`/`exhausted` → `ok` on any successful push; an `exhausted` view
  is also retried by a new product change (the change resets `attempts` to 0
  and state to `ok`) and by the nightly reconcile.
- `blocked` → re-evaluated on every selected run; clears when the guard passes.
- `paused` → only (a) a configuration save affecting the view (above), or (b) a
  manual "Sync now" / CLI run. Product changes still set `dirty` but do not
  unpause — EXCEPT the catalog-dependent reasons `validation_failed` and
  `product_limit`, where the catalog itself is the cause: any product or
  catalog change marking the view dirty also resets it to `ok`, so a fixed
  or shrunk catalog is pushed by the normal debounce. (Tested: refused push
  at the product cap, then reduce eligible products → next cron pushes.)

### Response classification

Classification uses the engine's machine-readable `code` (§11), never the
human `error` text. Applies to both `POST /catalog` and
`DELETE /stores/{key}/catalog`.

| Response | `code` | State | Merchant sees |
|---|---|---|---|
| Transport error, timeout, 5xx | — | `retrying` (+5 min, then +15 min) → `exhausted` after 3 | "Engine waking up" on first failure; system message at `exhausted` |
| 429 | `throttled` / `quota_exceeded` | `retrying` (`retry_reason = throttled`), honoring `Retry-After` if present (min 5 min); counts toward the 3 attempts | "Rate limited, retrying" |
| 409 | `run_in_progress` (clear only) | `retrying` (+5 min) | "Analysis run in progress — will clear when it finishes" |
| 403 | `invalid_key` | `paused` | "API key rejected or account inactive — check the key" |
| 403 | `store_limit` (+ `limit`) | `paused` | "Your AnswerEng plan allows `limit` store(s) and they are all in use. Disabling a view or clearing a catalog does not free a slot. Options: upgrade your plan; set this view's store key to one of your existing stores (listed here from `GET /stores`) if that is the store you meant; delete a store you no longer need (its slot frees when the 7-day undo window ends); or contact support@answereng.io." |
| 403 | `product_limit` (+ `limit`) | `paused` (catalog-dependent) | "This catalog exceeds your plan's `limit`-product limit" |
| 403 | `store_owned_elsewhere` | `paused` | "Store key is registered to another AnswerEng account — choose a different store key" |
| 404 | `unknown_store` (clear only) | `ok` — treated as already clear | (sync-log note only) |
| 409 | `store_pending_deletion` | `paused` | "This store is scheduled for deletion on <date>. Cancel the deletion in the Danger zone to resume syncing." |
| 422 | `validation_failed` | `paused` (catalog-dependent) | "Engine rejected the catalog data" + sanitized summary |
| any other 4xx, or missing/unknown `code` | — | `paused`, reason `unexpected` | "Engine refused the request (HTTP n)" |

The code list is a PHP enum mirrored from the engine's
`tests/fixtures/error_codes.json` (§11); a unit test fails if the fixture
gains a code the enum lacks. The mock engine returns exactly these bodies.

**Sanitized error extraction:** the sync log and admin messages NEVER store
or render a raw response body (pydantic 422 bodies echo submitted `input`
values). Stored detail is: HTTP status and the `code` (and `limit` when present) —
never the `error` text, which is human prose the engine may change. For 422,
only `loc` paths and `type` codes of at most the first 5 errors (no `input`,
no `msg` containing data), each capped at 200 characters; the whole detail
string is capped at 1,000 characters. Response bodies are never written to
`answereng.log` either.

Logging: dedicated `var/log/answereng.log`; the API key never appears in any
log or sync-log row (unit-tested by asserting on captured log output).

## 7. Admin status panel

Rendered on Catalog › AnswerEng › Status & AI Visibility
(`answereng/status/index`, ACL `Fullspec_AnswerEng::status`, with `::sync` and
`::delete_store` beneath it) with a store-view switcher; "All Store Views" is
the default scope below. The config section's Status group links to it. All
output via `$escaper`.

- **Default scope:** one table, a row per enabled view — store key, last sync
  time + outcome, product count, "Sync now".
- **Store-view scope:** the results page described in §7.2 (visibility,
  per-engine presence, share of voice, drafted fixes, sync controls: **Sync
  now** and **Test connection** are admin AJAX controllers; form key + ACL),
  with the last 20 log entries under Recent activity (GETs retry once on
  5xx/transport, within the shared budget in §7.2), and **"Open full results in AnswerEng →"** to
  `https://app.answereng.io/stores/{key}`, with the hint "Sign in there with
  this store view's API key." The link appears only when the engine URL is
  the production `https://api.answereng.io`; with any other engine the panel
  says "Connected to a test engine at {host:port}. Full results on
  app.answereng.io are only for stores that sync to AnswerEng." instead.
  `latest_run: null` renders a "no completed run yet" state.
- **Clear AnswerEng catalog** (the view's `current` remote store, enabled or
  not; ACL `Fullspec_AnswerEng::sync`; confirm dialog naming the store key):
  calls `DELETE /stores/{store_key}/catalog` only after the connection
  check in §7.1 passes. Copy: "Removes this
  store's products from AnswerEng and pauses weekly analysis. History and
  results are kept, and the store still counts toward your plan. The next
  sync restores the catalog." Disabling a view never clears automatically.
- **Danger zone — Delete store from AnswerEng** (§7.1).
- No JavaScript beyond these buttons and the Danger-zone dialog; no
  external assets.

### 7.1 Danger zone — store deletion

Deletion is the only irreversible action in the module, so every layer
makes it deliberate.

**Remote store registry** — remote identities are separate from view
associations:
- `answereng_remote_store`: one row per remote store identity —
  `engine_url`, `account_id` (from `X-AnswerEng-Account`, §11.1),
  `store_key`, unique on that triple; `remote_status` (`active`,
  `pending_deletion` with `purge_after`, `deleted`), `first_synced_at`.
- `answereng_remote_store_view`: which views have pushed to it —
  (`remote_store_id`, `store_view_id`) unique, `last_synced_at`.
  Two views (e.g. A disabled, then B enabled with A's old key) share ONE
  identity row with two associations; neither takes the other's history.
- Any 2xx push upserts the identity and the (identity, view) association
  (§6 step 5). Forget deletes one association; the identity row goes when
  its last association does.

**Roles are derived, never stored**, so they can't go stale. For a view,
an associated identity is **current** when it equals the view's current
effective target: the view is enabled, its configured store key equals the
identity's `store_key`, the effective engine URL equals `engine_url`, and
`account_id` equals the account last seen for the CURRENT API key (recorded
by any sync, Test connection or panel load; unknown right after a key
change, in which case nothing is current until the next contact).
Everything else associated with the view is **previous**. A config save
therefore moves A to previous the moment the key changes to B — before B
ever syncs.

**Active users of an identity** = every view (in this Magento) for which
it is currently `current`.

**Connection check (every destructive action: clear, delete, cancel).**
Before the request, the module calls `GET /stores` with the CURRENT engine
URL and key and proceeds only if (a) the current effective engine URL
equals the row's `engine_url` and (b) the response's
`X-AnswerEng-Account` equals the row's `account_id`. Otherwise the action is
refused: "`<key>` was synced through a different AnswerEng connection
(engine `<url>`, account `<acct>`). Reconnect with that connection to
manage it, or Forget it here." **Forget** removes this view's association
only (no remote call; the remote store and its slot are untouched — the
copy says so).

**Cross-view check (clear, delete).** If the identity has an active user
OTHER than the view acting, the action is refused: "`<key>` is the active
AnswerEng store for store view `<name>`. Disable that view or change its
store key first." Clear and delete therefore only ever affect a store that
is current for the acting view alone, or for no view.

- **Where:** a collapsed "Danger zone" fieldset, store-view scope only,
  listing the identities associated with the view (current first, then
  previous), each with its status, any other views using it, and actions:
  **Delete** (active identities), **Cancel deletion** (pending),
  **Forget** (previous associations). Never at default or website
  scope, never in the CLI, never automatic (empty catalogs, disabled views
  and store-key changes never trigger it).
- **Who:** separate ACL resource `Fullspec_AnswerEng::delete_store`. Custom
  admin roles do not get it unless explicitly granted. (Roles with
  "Resource Access: All" include every resource — Magento has no way to
  withhold one; the docs say so.)
- **Warning copy (red, before any input):** "Deleting `<key>` schedules
  permanent removal of everything AnswerEng holds for this store: its
  catalog, questions, every analysis run and raw AI answer, diagnoses and
  drafted fixes, and its dashboard project with its reviewers and
  invitations. You have 7 days to cancel. After that it cannot be recovered,
  and the store's plan slot is freed. Results already shown in the
  dashboard will disappear."
- **Confirmation:** a modal requiring (a) typing the store key exactly and
  (b) ticking "I understand this cannot be undone after 7 days". The
  confirm button stays disabled until both hold; the controller re-validates
  both server-side (form key + ACL + typed key == the identity's
  `store_key`) and runs the connection and cross-view checks.
- **Request:** `DELETE /stores/{key}` with body
  `{"confirm_store_key": "<key>"}` (§11.3). On success the identity
  becomes `pending_deletion` with `purge_after`. Whether syncing stops is
  decided from the CURRENT effective target at that moment, not from
  history: if the identity is current for the acting view, that view is
  set to disabled (a config save, so the revision bumps and in-flight syncs
  are discarded) and the panel shows a persistent banner: "Scheduled for
  deletion on <purge_after>. **Cancel deletion**". If it is previous (e.g.
  the view now targets B), the view's syncing is left alone — the common
  "free A's slot so B can sync" case.
- **Cancel:** `POST /stores/{key}/restore` (after the connection check);
  the identity returns to `active`. It does NOT re-enable the view (the
  merchant re-enables it deliberately). `purge_in_progress` → "Deletion has
  already started and can no longer be cancelled."
- **Status refresh:** whenever the panel loads, identities are reconciled
  against `GET /stores` for their connection (only when the connection
  check passes): one whose key is gone after its `purge_after` becomes
  `deleted` (kept for the merchant's record; Forget removes it).
- **Errors:** `run_in_progress` → "An analysis run is in progress; try
  again in a few minutes." `unknown_store` → "AnswerEng has no store
  `<key>` (it may already have been deleted)." Others per §6.
- **Audit in Magento:** every request/cancel writes a sync-log entry with the
  admin username, and an `answereng.log` line.

**Test connection** (never pushes, never creates a store): calls
`GET /stores` with the effective engine URL and key.
- Transport/5xx after one retry → "Engine unreachable (it may be waking up —
  try again in a minute)".
- 403 → classified by `code` as in §6 (`invalid_key` → "API key rejected or
  account inactive").
- 200 and the view's effective store key IS in the list → "Connected —
  store `<key>` found"; the panel then reads `/summary` (a `latest_run:
  null` there means "Connected — waiting for the first analysis run").
- 200 and the key is NOT in the list → "Connected — first sync needed
  (store `<key>` will be created on first sync; it uses 1 of your plan's
  stores)". Existing workspace store keys are listed for reference.
- A `/summary` 404 anywhere in the panel is rendered as "not synced yet",
  never as a credential failure. Exception: when the view's recorded
  `synced_store_key` equals its current store key (it synced before with this
  key, so the store was deleted in AnswerEng or the API key changed), the
  visibility section says "Out of sync with AnswerEng. Sync now to see
  results." and a `Synced` header pill becomes an amber "Out of sync". Other
  sync states (Retrying, Sync failed, …) keep their own pill.

**Snapshot and cache identity.** Both the 15-minute cache entry and the
persisted fallback snapshot are keyed by a connection fingerprint:
`sha256(effective engine URL | API key | effective store key)` (the key only
inside the hash; the fingerprint is stored, never the key).
- A snapshot whose fingerprint differs from the current one is never shown.
- Saving AnswerEng configuration at any scope deletes cache entries and
  snapshots for every view whose fingerprint changed.
- When the engine is unreachable and a matching snapshot exists, the panel
  shows it labeled **"Stale — last fetched <date time>"**; with no matching
  snapshot it shows "Results unavailable — engine unreachable".

### 7.2 Results page (store-view scope), as built 2026-11-20

The store-view page matches the WooCommerce plugin's results: the same
sections, copy and engine labels (spec
`docs/superpowers/specs/2026-11-20-magento-results-parity-design.md`). Top to
bottom, with the header (status pill, **Refresh**, Settings) above:

1. **Alerts:** stale banner, pending-deletion banner with Cancel, the sync
   state message, and the "Not synced yet" / "Out of sync" notices. A jump
   link "N drafted fixes to review →" sits under the header when fixes exist.
2. **AI visibility** (open): headline rate with the change since the last
   run, its 95% interval, and a trend chart (inline SVG, y-axis from zero;
   one point shows WooCommerce's "appears once two weekly samples" note).
3. **Per-engine presence** and **Share of voice** (open, side by side; they
   stack under 1024 px). Your store is brand green, competitors gray; the
   competitor-list link goes to the Brand group of the settings.
4. **Drafted fixes** (open): kind and priority pills, engine, the buyer
   prompt, "Why you're missing", the draft text with **Copy draft**, and
   **Mark as applied**. A collapsed **Applied (N)** list shows the date and
   **Undo**. The Mark and Undo buttons need `Fullspec_AnswerEng::fixes`.
5. **Sync** (collapsed when healthy): last sync, products sent, an
   auto-sync-off line when applicable, Sync now, Test connection, Clear;
   Recent activity (collapsed) and the Danger zone below.

**Read budget.** The engine reads behind one page view (`GET /stores`, the
summary, suggested fixes, applied fixes) share one 20-second budget. Each
attempt takes at most 15 s, and a retry happens only with at least 5 s left.
A transient failure (transport error or 5xx) stops the remaining reads; the
page then falls back to the stale snapshot, with the summary and both fix
lists stored together (`snapshot_json`, `snapshot_fixes_json`,
`snapshot_applied_json`, all `mediumtext`, valid only for the matching
fingerprint). If the fixes cannot be read and have no snapshot, the section
says they are unavailable right now.

**Mark and undo** (`POST answereng/action/fixapplied`, fields `store`,
`fix_id`, `op` = `mark|unmark`; form key; ACL `::fixes` under `::status`):
- 2xx: the fix-list caches are dropped; success.
- 404 (engine code `unknown_store`): "That fix no longer exists in
  AnswerEng. Refresh to see the current list." Caches are kept.
- 409: refused.
- Any other 4xx, including 429: "AnswerEng didn't accept that change.
  Nothing was changed."
- No response, or 5xx: "Couldn't confirm that change. AnswerEng didn't
  respond in time. Refresh in a moment to see whether it was applied." and
  the fix lists are dropped, so the next view reads the real state.

**Settings.** The **What data leaves this site** group (default scope,
collapsed) lists what is sent (SKU, name, short description, price,
category, storefront-visible attributes, brand and competitor names, store
key) and what is not (customer names, emails and addresses; orders and
payment data; product images, analytics and telemetry), and
names the configured engine URL. The API key note points newcomers to sign up
at answereng.io. Auto-sync is described in §3.

## 8. Testing and local development

**Unit (PHPUnit 10, no Magento runtime):** CatalogBuilder mappings and
contract key order/`{}`, catalog changing between id capture and chunk
loads (deleted ids drop out, no skips), chunk failure aborts with no push;
Syncer revision guard (config change mid-push discards outcome), retry
selection of a clean `retrying` view, config-save queueing; Test connection
outcomes incl. pre-first-sync; Config effective defaults, uniqueness validation,
key never logged; Syncer lock, guard, dirty-flag race, every error-table row
including retry scheduling and give-up; EngineClient + panel against
`tests/fixtures/read_api/`; a contract test recomputing `CONTRACT.sha256`.

**Static (CI gates):** `magento/magento-coding-standard` (phpcs), PHPStan
level 6 + `bitexpert/phpstan-magento`, `composer validate`.

**Package source:** the Mage-OS mirror (`https://mirror.mage-os.org/`) for
both CI and the local store — no Adobe Marketplace keys required.

**Local end-to-end:**
- `dev/docker-compose.yml`: Magento 2.4.8 + sample data, OpenSearch, MariaDB,
  cron enabled, on port 8894 (8888/8892/8893 are taken).
- `dev/mock-engine/`: Python stdlib server serving the read-API fixtures,
  validating each `POST /catalog` against the contract (including
  `products` min 1) and returning a real pydantic-shaped 422 when invalid,
  saving payloads to `dev/mock-engine/received/`, implementing
  `DELETE /stores/{key}/catalog`, and with switchable modes that return the
  bodies from `error_codes.json`: `cold-504`, `slow`, `throttle-429`,
  `invalid-key`, `store-limit`, `product-limit`, `ownership`,
  `run-in-progress`, `missing-code` (a 403 without `code`). It implements
  `DELETE /stores/{key}` (confirmation check, pending state with
  `purge_after`) and `/restore` (including a `purging` mode returning
  `purge_in_progress`), returns `store_pending_deletion` for a pending
  store, and sends `X-AnswerEng-Account` derived from the API key it was
  given (so two keys simulate two workspaces); the purge itself is not
  simulated. It also enforces a configurable store cap
  across distinct store keys, so enabling a second view on a 1-store plan
  reproduces the real 403.
- `docs/integration-checklist.md`, executed and recorded: two enabled views,
  debounced push after save, moving a product between websites (both
  views resync), deleting the last product (clear called, `catalog_cleared`; adding one
  back restores via push), manual Clear on a disabled view, mass
  update, import, over-cap refusal, duplicate-key rejection, second view
  over the plan's store cap (`store_limit`, not `invalid_key`), each 403 class,
  429 and 504 handling with exhaustion, unpause on config save, changing the
  API key hides the old snapshot, failed nightly push on a clean view is
  retried at +5 min, config change mid-push discards the stale outcome and
  re-queues, Test connection before first sync, Sync now, CLI, Danger zone
  (hidden without `delete_store` ACL and for never-synced views; button
  disabled until key typed + box ticked; server rejects a mismatched key;
  success disables the view and shows the Cancel banner; Cancel restores
  without re-enabling), changing the store key keeps the old store listed
  as `previous` and it can be deleted without touching current syncing,
  switching to a different workspace key or engine URL makes clear/delete/
  cancel refuse with the connection message until reconnected or
  forgotten, Forget makes no remote call, a push superseded by a config
  change still appears as `previous`, changing the key from A to B shows A
  as previous immediately and deleting A leaves B's syncing enabled, view B
  reusing disabled view A's key shares one identity, and A cannot clear or
  delete it while B is using it, clean uninstall.

**CI:** GitHub Actions, PHP 8.1 and 8.3: unit, phpcs, PHPStan, contract.
Advisory (private repo on GitHub Free cannot require checks).

## 9. Release and sequencing

1. Engine PR (§11) built and merged with its tests; CI green.
2. Engine deploy — owner-scheduled and owner-confirmed. `main` already
   carries PR #13 and the M2 work (PR #14, runbook-only), so the error-code /
   catalog-clear / deletion changes ship either with the M2 rollout or
   cherry-picked onto a release worktree at the live commit; the owner picks
   at deploy time. Never `cdk deploy` from `main` outside the M2 runbook.
   **Settled:** the deletion migration is 003 and depends on M2's 002
   being applied first (migrations apply in filename order and are
   checksum-tracked), so this work deploys only AFTER the M2 rollout —
   never cherry-picked onto the pre-M2 live commit.
3. WooCommerce: copy-only PR adding `error_codes.json` to its fixtures and
   `CONTRACT.sha256` (no behavior change), keeping checksum parity.
4. Magento: `bin/build-zip.sh` produces a Composer-installable zip. After
   step 2, one live smoke test with a test key against `api.answereng.io`
   (push, Test connection, clear, push again; then delete + restore on a
   throwaway store key, never letting it reach purge) — owner-confirmed —
   then a
   tagged GitHub release with the owner's go-ahead.

## 10. Out of scope (v1)

Native results/fixes pages; fix write-back; incremental upsert and catalogs
over 5,000 products (engine v2); dashboard UI for pending deletion
(follow-up issue); WooCommerce use of the new codes, clear or delete
endpoints; Marketplace submission; per-customer-group or
tier pricing; message-queue or indexer-based sync.

## 11. Workstream A — engine changes (`answereng-engine`)

### 11.1 Machine-readable error codes

Every error response on the v1 connector routes gains a stable `code`
field. The existing `error` text stays (WooCommerce and humans read it);
connectors branch only on `code`.

| Route(s) | Status | `code` | Extra |
|---|---|---|---|
| ingestion, read API, clear | 403 | `invalid_key` (tenant unknown or inactive) | |
| `POST /catalog` | 403 | `store_limit` | `limit` (int) |
| `POST /catalog` | 403 | `product_limit` | `limit` (int) |
| `POST /catalog` | 403 | `store_owned_elsewhere` | |
| `POST /catalog` | 422 | `validation_failed` | existing `errors` unchanged |
| read API, clear, delete, restore | 404 | `unknown_store` | |
| clear, delete | 409 | `run_in_progress` | |
| `POST /catalog`, clear, summary, fixes | 409 | `store_pending_deletion` | `purge_after` (ISO 8601) |
| delete | 422 | `confirmation_mismatch` | |
| restore | 409 | `purge_in_progress` | |
| API Gateway | 403 | `invalid_key` | `GatewayResponse` `INVALID_API_KEY` |
| API Gateway | 429 | `throttled` / `quota_exceeded` | `GatewayResponse` `THROTTLED` / `QUOTA_EXCEEDED` |

- Gateway responses are added in `infra/stacks/api_stack.py` next to the
  existing `Cors4xx`/`Cors5xx`, carrying the same CORS headers and a JSON
  body `{"error": "...", "code": "..."}`. `MISSING_AUTHENTICATION_TOKEN` is
  NOT overridden (API Gateway also uses it for unknown routes).
- New frozen fixture `tests/fixtures/error_codes.json`: one example body per
  code; engine tests assert each handler path returns exactly its fixture
  body; the file joins `CONTRACT.sha256`.

**Account identity header.** Every response from an authenticated v1
connector route (ingestion, read API, clear, delete, restore — success and
handler-produced errors) carries `X-AnswerEng-Account: acct_<id>`, an
opaque, stable, non-secret identifier of the tenant: `acct_` + the 32 hex
digits of a new random `tenant.public_id UUID` (migration 003, default
`gen_random_uuid()`). Random, so no tenant id or key material is
recoverable from it, and no server-side secret is needed. It survives key
rotation (same tenant) and differs across workspaces. A header rather than a body field,
so no existing response shape changes (WooCommerce and the dashboard ignore
it).

### 11.2 `DELETE /stores/{store_key}/catalog`

- Auth: `x-api-key` on the existing usage plan (same as `/catalog`).
- Ownership exactly like the read API: unknown store, or one owned by
  another tenant (or unowned for non-operators) → 404 `unknown_store`
  (no existence leak).
- Under the per-store lifecycle lock (§11.3): an active run → 409
  `run_in_progress` (no mid-run deletion; connectors retry); a store
  pending deletion or purging → 409 `store_pending_deletion`.
- Otherwise deletes all `product` rows for the store in the same transaction →
  200 `{"store_key": "...", "products_deleted": n}`. Idempotent: an
  already-empty store returns 200 with `products_deleted: 0`. Prompts,
  runs, observations, diagnoses, fixes and the store row are untouched;
  the store still counts toward the plan.
- Served by the ingestion Lambda (it already owns tenant/ownership logic and
  `product` writes); new API Gateway method on the existing `/stores/{store_key}`
  resource.
- **Weekly orchestration skips product-less stores:**
  `repo.stores_for_weekly_run` adds `AND EXISTS (SELECT 1 FROM product
  WHERE store_id = s.id)`, so a cleared store stops consuming model calls
  until a push restores its catalog. (This also covers any store that never
  had products.)
- Read API unchanged: a cleared store's summary still returns its history.

### 11.3 Scheduled store deletion

Two phases: a reversible **request** and, 7 days later, an irreversible
**purge**.

**Schema (migration 003):**
- `store.deletion_state TEXT NULL CHECK (deletion_state IN ('pending',
  'purging'))` — NULL means active. `pending` is restorable; `purging` is
  not, and only the purge job ever sets it.
- `store.deletion_requested_at`, `store.purge_after` (TIMESTAMPTZ NULL),
  `store.deletion_requested_by` (API key id).
- `store_purge_progress` (store_id PK → store ON DELETE CASCADE,
  `s3_done BOOLEAN`, `attempts INT`, `last_error TEXT`, `updated_at`) so a
  purge resumes where it stopped.
- `email_outbox` (id, recipient, template, payload JSONB, state
  `pending`/`sent`/`failed`, attempts, next_attempt_at, sent_at,
  created_at) — no FK to store, so it outlives the purge.

**Per-store lifecycle lock.** Every operation that admits a run or destroys
data serializes on a transaction-scoped advisory lock
`pg_advisory_xact_lock(42002, store_id)` (new namespace; 42001 is prompt
seeding): **catalog push**, async prompt seeding, run admission in
`plan_run`, catalog clear, deletion request, restore, and the purge job's
state transition. Inside the lock each one re-reads the store's current
state, so check-then-act cannot interleave with another.

**Catalog push under the lock.** `POST /catalog` for an EXISTING store runs
as one transaction: take the lifecycle lock, re-read `deletion_state`
(pending/purging → 409 `store_pending_deletion`, nothing written), then
`upsert_store` (brands/competitors) and `replace_products`, then commit. A
NEW store is created and populated inside one transaction that takes the
lock on the new id before inserting products, so no deletion request can
see it half-written. Today's separate lookup → upsert → replace steps are
merged into this one transaction. **Async prompt seeding** (the
`seed_prompts` self-invoke after a first push) takes the lifecycle lock
and re-checks that the store still exists, is not pending/purging and
still has products before inserting prompts; otherwise it does nothing.

**`DELETE /stores/{store_key}`** (x-api-key, same usage plan):
- Body must be `{"confirm_store_key": "<store_key>"}` matching the path
  exactly, else 422 `confirmation_mismatch` — no generic HTTP client can
  delete by accident.
- Ownership as the read API (unknown/foreign → 404 `unknown_store`).
- Under the lifecycle lock: an active run for the store (below) → 409
  `run_in_progress`; otherwise sets `deletion_state = 'pending'`,
  `deletion_requested_at = now()`, `purge_after = now() + 7 days`,
  `deletion_requested_by`, and enqueues the "deletion requested" email in
  the SAME transaction → 202
  `{"store_key", "status": "pending_deletion", "purge_after"}`.
  Idempotent: repeating while `pending` returns 202 with the ORIGINAL
  `purge_after` (the window never extends silently); while `purging` → 409
  `store_pending_deletion`.

**`POST /stores/{store_key}/restore`:** ownership as above; under the
lock, a single `UPDATE store SET deletion_state = NULL, … WHERE id = ? AND
deletion_state = 'pending'` plus the "deletion cancelled" email enqueue →
200 `{"store_key", "status": "active"}`. Not pending → 200 (idempotent).
`purging` → 409 `purge_in_progress` (cannot be undone any more). Already
purged → 404 `unknown_store`.

**Active run** (used by clear, delete and purge): `run.status = 'running'`
AND `started_at` is within the last 12 hours. `RunMachine` currently has
no timeout (Step Functions' default is one year); this work sets it to 12
hours so the two agree, and a crashed run that never finalized cannot
block deletion forever.

**Run admission (`plan_run`).** Under the lifecycle lock, `plan_run`
re-checks eligibility at execution time — store exists, `deletion_state IS
NULL`, at least one product — before `create_run`. If ineligible it
creates no run and returns `{"skipped": true, "reason": …}`; the pipeline
state machine gains a Choice that ends the execution successfully on
`skipped`. Scheduler filtering (below) remains an optimization; this check
is the guarantee, covering executions queued before a clear or deletion.

**While pending:**
- `POST /catalog`, catalog clear, summary and fixes → 409
  `store_pending_deletion` with `purge_after`.
- `GET /stores` still lists it, with `"status": "pending_deletion"` and
  `purge_after` (additive fields; active stores carry `"status":
  "active"`), so connectors and the dashboard can offer Cancel.
- Weekly orchestration skips it (`stores_for_weekly_run` adds
  `AND s.deletion_state IS NULL`); `plan_run` admission refuses it anyway.
- `purging` stores behave the same on every route, except restore (409
  `purge_in_progress`) and `GET /stores` (`"status": "purging"`).
- It still counts toward the plan's store cap until purged.

**Purge job:** a daily EventBridge rule invokes the ingestion Lambda with
`{"admin_action": "purge_due_stores"}` (admin actions are already
unreachable through API Gateway). It selects stores that are due
(`deletion_state = 'pending' AND purge_after <= now()`) or already
`purging` (a resumed purge). For each:
1. **Commit point of no return.** Under the lifecycle lock: if an active run
   exists, skip until the next day. Otherwise
   `UPDATE store SET deletion_state = 'purging' WHERE id = ? AND
   deletion_state = 'pending' AND purge_after <= now()` and insert the
   `store_purge_progress` row, in one transaction. From here restore
   returns 409 `purge_in_progress`; nothing has been deleted yet, so a
   restore that won the lock first simply leaves this store unselected.
2. **S3:** if `s3_done` is false, delete every object under
   `runs/{run_id}/` for the store's runs (idempotent; listing what remains
   makes it resumable). Success sets `s3_done = true`. Any failure records
   `attempts`/`last_error` and leaves the store `purging` — still blocked
   from every route, still not restorable — for the next daily run. After 5
   failed attempts an operator alarm fires (CloudWatch metric on the
   purge job's failure count).
3. **One database transaction**, in FK-safe order: delete `run` rows
   (cascading observations, diagnoses, fix drafts) → `prompt` → `product`
   → for the store's catalog `project`: `invitation_delivery` and
   `invitation` rows for it, `project_reviewer` rows, set
   `audit_event.project_id = NULL` on its audit rows (audit history is
   kept), delete the `project` → delete the `store` (cascading its
   `store_purge_progress` row). In the SAME transaction: write the
   `audit_event` (`action = 'store_purged'`, `target_id = store_key`,
   details: counts per table, S3 objects deleted, who requested, when) and
   enqueue the "store deleted" email with recipient and message data
   resolved now, while the tenant and store data still exist.
Request and restore also write audit events (`store_deletion_requested`,
`store_deletion_cancelled`) in their own transactions.

**Owner emails** (SES, existing sender keys@answereng.io, reply-to
support@answereng.io), to the tenant's registered email: on request (what
will be deleted, the purge date, how to cancel), on cancel, and after purge.
All three go through `email_outbox`, written in the same transaction as the
state change they describe, so a committed change always has its email and
an aborted one never does. The outbox drains right after commit (best
effort) and again at the start of every purge-job run: `pending` rows past
`next_attempt_at` are sent, with backoff (1 h, 6 h, 24 h) and `failed`
after 5 attempts (alarmed). Email failure never blocks or reverts a state
change.

**Dashboard:** out of scope for this cycle, but it must not break: the
store page will receive 409 `store_pending_deletion`. A follow-up Linear
issue covers a "pending deletion" banner with Cancel in the dashboard.

### 11.4 Tests

Unit/integration (Postgres) for: each code path's exact body vs. fixture;
clear success, idempotency, 404 for unknown/foreign store, 409 while
running; weekly selection excludes product-less and pending stores;
**concurrency** (two real connections, Postgres integration): a catalog
push racing a deletion request — either the push commits first (deletion
then proceeds over the new catalog) or the deletion commits first (push
gets 409 `store_pending_deletion` and writes nothing), never products
written into a pending store; async prompt seeding after a deletion
request or clear inserts nothing; restore racing
the purge transition — exactly one wins, and a store that reached `purging`
can never return to active; `plan_run` admission racing a deletion request
or a catalog clear — either the run is admitted first (the destructive call
then gets `run_in_progress`) or the destructive call commits first (the
run is skipped), never both; an execution queued before a clear/deletion is
skipped at `plan_run`; a crashed `running` run older than the state
machine timeout no longer blocks; purge resumes after an S3 failure with
`s3_done` honored, and a restore during that `purging` window returns 409
`purge_in_progress`; outbox: the purge email row is committed with the
purge and still sent after the store row is gone, retries with backoff,
and an aborted transaction leaves no email row; `X-AnswerEng-Account` is
present on every authenticated route, identical across key rotation and
different across tenants;
delete: confirmation mismatch, foreign store 404, run-in-progress 409,
idempotent repeat keeps the original `purge_after`, every guarded route
returns `store_pending_deletion`, restore before purge, restore after purge
404; purge: a fully populated store (products, prompts, runs with
observations/diagnoses/fixes, catalog project with a reviewer, an
invitation + delivery, audit rows) is removed in FK-safe order with audit
rows retained (`project_id` NULL) and a `store_purged` event written; S3
failure leaves the database untouched; a store not yet due and one with a
running run are skipped; the freed slot lets a new store be created. CDK
synth asserts the three gateway responses, the new DELETE/POST methods with
API key required, and the daily purge rule. Existing route-pinning tests
extended with the new routes.

### 11.5 Endpoints the Magento module uses

`GET /stores`, `GET /stores/{key}/summary`, `POST /catalog` (sync),
`DELETE /stores/{key}/catalog`, the scheduled-deletion routes (§11.3), and,
since the results page (§7.2):

- `GET /stores/{key}/fixes?status=suggested|applied`
- `POST /stores/{key}/fixes/{id}/applied` (mark applied)
- `DELETE /stores/{key}/fixes/{id}/applied` (undo)

The fixtures under `Test/Fixtures/` are the frozen contract for these.
