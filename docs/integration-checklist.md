# Integration checklist — executed record

Environment: local Magento Open Source 2.4.8-p5 from the Mage-OS mirror, with sample data
(port 8894, docker compose with markshust images, PHP 8.3, MariaDB 11.4, OpenSearch 2.12;
see `docs/dev-environment.md`) + mock engine (`dev/mock-engine`, port 8895).
Module installed as a Composer path repository (copy mode) from branch `feat/module-v1`.
Date: 2026-10-08 (all times UTC).

Views: **A** = `default` (Main Website, 180 eligible sample products, key `localhost`);
**B** = `second_view` (website `second`, 32–33 eligible products, key `127.0.0.1`).
API keys: `ae_test` (account `acct_e1e3811…`), `ae_other` (a second mock workspace).

Driven over HTTP with an admin session (`magento-local/scripts/admin.py`: real config-form
saves and panel-action POSTs with the page's form key), through the real cron runner
(`bin/magento cron:run --group=default`), product saves through `ProductRepository`,
mass updates through `Product\Action::updateAttributes`, CSV imports through the
ImportExport `Import` model, and a headless Chromium (playwright-cli) for rows 18 and 20.
Engine traffic is evidenced by `var/log/answereng.log` (method, path, status, code) and
the payloads saved in `dev/mock-engine/received/`.

## Bugs found and fixed during the run

| Commit | Bug | Found by |
|---|---|---|
| 1b1b402 | API key decrypted twice: `etc/config.xml` declares the Encrypted backend model, so ScopeConfig already returns plaintext; decrypting again sent binary garbage as `x-api-key` and Guzzle refused every request. Nothing could ever reach the engine. | first panel load |
| 9a6f80a | `bin/magento answereng:sync` failed with "Area code is not set" (catalog export's store emulation needs an area); now sets the cron area. | row 25 |
| ee7ff86 | No merchant copy anywhere: the panel showed raw codes ("paused (store_limit)") and Sync now said "AnswerEng refused the request (HTTP 403 store_limit limit=1)". Added the spec §4/§6 messages (store_limit names the limit and every option, cleared, blocked, exhausted, …) to Sync now/CLI results, the store-view panel and the default-scope rows. | rows 4, 9 |
| a82581e | `module:uninstall --remove-data` left all four tables and the `answereng/*` config rows: Magento 2.4.8's UninstallCollector only runs `Setup\Uninstall` for modules with a `setup_module` row, which only a `setup_version` creates. Added `setup_version="1.0.0"`. | row 26 |
| c2e333f | Defensive: transport error text quotes a rejected header value, which would put the API key into `answereng.log` and the sync log (seen with the garbage key above); the key is now redacted. | first panel load |

## Time simulations (instead of waiting)

| When | Row | Simulation |
|---|---|---|
| 20:39:04 | initial push | `answereng_sync_state.last_change_at` −6 min (5-min debounce) |
| 20:39:37 → 20:41:49 | 2, 3, 4, 5, 6, 7 | `last_change_at` −5 min before each `cron.sh` |
| 20:42:37 | 13 | `last_change_at` −5 min |
| 20:42:46–20:42:48 | 11 | `last_change_at` −5 min, then `next_attempt_at` −20 min after the 1st and 2nd 504 |
| 20:43:21 | 11 (extra) | `next_attempt_at` −6 min (clean view failed by reconcile) |
| 20:43:30 | 12 | `next_attempt_at` −6 min (past the 300 s throttle window) |
| 20:47:07 | 15 | `last_change_at` −5 min |
| 20:53:48 | 33 | `dirty=1, last_change_at` −10 min on B |
| 20:56:17–20:56:29 | 4, 9 re-runs | `last_change_at` −5 min |

The nightly `answereng_reconcile` was run on demand by overriding its cron expression to
`* * * * *` in `core_config_data` for that single `cron:run` (removed straight after).

## Checklist

| # | Scenario | Steps | Expected | Result |
|---|---|---|---|---|
| 1 | Two views enabled | Enable views A and B with distinct keys; save | Both rows appear at default scope; both marked dirty | ✅ 20:35 — keys filled from base-URL hosts (`localhost`, `127.0.0.1`); default-scope table lists both; `dirty=1` for both |
| 2 | Debounced push | Edit a product; wait 5 min; `bin/magento cron:run` | One push per view in `dev/mock-engine/received/` | ✅ 20:39 — save of 24-MB01 (both websites) set both dirty; cron at +0 min sent nothing; after `last_change_at` −5 min one push per view, both containing the new title |
| 3 | Product moves website | Move a product from A's website to B's | Both views resync; product only in B's payload | ✅ 20:39:48 — MH01 websites 1→2: both views dirty and pushed; A payload 180 products without MH01, B payload 33 with MH01 |
| 4 | Last product removed | Disable every product in A | Mock receives DELETE /stores/A/catalog; panel says catalog cleared | ✅ after fix ee7ff86 — 20:40:04 mass status=disabled at view A → `DELETE /stores/localhost/catalog` 200, state `ok (catalog_cleared)`, B untouched. First run the panel showed only the code (❌, fixed); re-run 20:56:27: panel and default row read "No eligible products — the AnswerEng catalog is cleared and weekly analysis is paused until products return." |
| 5 | Product back | Re-enable one product | Next push restores the catalog | ✅ 20:40:49 — one product re-enabled → `POST /catalog` with 1 product, reason cleared |
| 6 | Mass update / import | Mass attribute update; CSV import | Every enabled view dirty, then pushed | ✅ 20:40:58 mass update (2,040 ids) → both dirty → both pushed; 20:41:13 CSV import (append, 1 row) → both dirty → both pushed with the imported title; 20:41:49 CSV import with Delete behaviour also marked both dirty |
| 7 | Over 5,000 | Generate 5,001 eligible products in A | blocked (too_many_products), no request | ✅ 20:41:41 — 4,821 generated products imported (180 + 4,821 = 5,001): A `blocked (too_many_products)`, log "5001 eligible products", no request for A (B pushed). Deleting them (import Delete) unblocked A on the next run. (Spec §4's persistent admin *system message* is not implemented; see concerns.) |
| 8 | Duplicate key | Give B the same key as A | Config save refused with the store-view name | ✅ "AnswerEng store key "localhost" is already used by store view Default Store View (default). Store keys must be unique."; value not saved |
| 9 | Store cap | Mock --store-cap 1, enable a second view | paused store_limit (not invalid_key); message names the options | ✅ after fix ee7ff86 — state `paused (store_limit)` from the first run (20:42:05) but the message was generic (❌, fixed). Re-run 20:56:17 via cron: panel, default row and Sync now read "Your AnswerEng plan allows 1 store(s) and they are all in use. Disabling a view or clearing a catalog does not free a slot. Options: upgrade your plan; set this view's store key to one of your existing stores (Test connection lists them) …; delete a store you no longer need …; or contact support@answereng.io." |
| 10 | Each 403 class | modes invalid-key, product-limit, ownership, missing-code | invalid_key / product_limit / store_owned_elsewhere / unexpected | ✅ 20:42:14–16 — paused `invalid_key`, `product_limit` (detail limit=5000), `store_owned_elsewhere`, `unexpected` (HTTP 403, no code) |
| 11 | 504 then exhaustion | mode cold-504; run cron 3× (advance next_attempt_at) | retrying → retrying → exhausted; nightly retries | ✅ 20:42:46–48 — retrying (attempt 1, +5 min) → retrying (2, +15 min) → exhausted (3); next `answereng_sync_dirty` made 0 requests; `answereng_reconcile` 20:43:01 pushed (ok). Extra: a failed reconcile push on a clean view (dirty=0) went retrying +5 min and was picked up by the 5-minute job when due |
| 12 | 429 | mode throttle-429; Sync now twice | second Sync now refused until Retry-After passes | ✅ 20:43:30 — 1st: "Rate limited" (retrying, retry_reason throttled, next +300 s = max(60, 300)); 2nd: "Rate limited by AnswerEng — try again after 2026-10-08 20:48 UTC." with no POST sent; CLI the same message, exit 1; after the window Sync now pushed |
| 13 | Unpause on config save | From paused invalid_key, save the API key | state ok, dirty, next cron pushes | ✅ 20:42:28 — save at default scope: revision 3→4, `ok`, dirty; next cron pushed A (B, at the 1-store cap then, re-paused store_limit as expected) |
| 14 | Snapshot identity | View results, change API key to a second key, stop the mock | panel shows "Results unavailable", never the old numbers | ✅ 20:44–20:45 — after switching to `ae_other` with the mock stopped: "Results unavailable — engine unreachable." Note: config saves do not delete the old cache entry/snapshot (spec §7 says they should); they are never shown for another fingerprint, but switching back to `ae_test` with the mock down showed the 15-min cached result for that same identity as fresh |
| 15 | Config change mid-push | mode slow; Sync now; change brands during the push | log shows superseded; next cron pushes new brands | ✅ 20:46 — brands saved from a second admin session during the 35 s push (the client timed out at 30 s); log "superseded by a configuration change", Sync now says the new settings will sync shortly; next cron payloads carry `["Luma", "Luma Active"]` |
| 16 | Test connection | Before first sync; after first sync | "first sync needed (uses 1 plan store)"; then found/waiting | ✅ 20:36: "Connected — first sync needed (store "localhost" will be created on first sync; it uses 1 of your plan's stores)." 20:47: "Connected — store "localhost" found. Stores in this workspace: 127.0.0.1, localhost." (the "waiting for the first run" wording was seen in the panel for a product-less store; the mock's summary for a store with products always has a run) |
| 17 | Danger zone ACL | Role without delete_store | Danger zone hidden; direct POST to answereng/action/delete denied | ✅ role "AnswerEng sync (no delete)" (user `aesync`): no Danger zone, Sync now still shown; direct POSTs (valid form key) to delete, cancel and forget → HTTP 403 denied page; registry unchanged |
| 18 | Delete flow | Type key + tick box; Delete | button disabled until both; mock pending_deletion; view disabled; Cancel banner | ✅ 20:50:56 in headless Chromium: button disabled with partial key + tick, enabled with exact key + tick, disabled again on untick; Enter in the key field did not submit the config form; click → mock `pending_deletion` (purge 2026-10-15 20:50 UTC), A `enabled=0` (revision bump), banner "scheduled for deletion on 2026-10-15 20:50 UTC" + Cancel deletion; sync log `deletion_requested` by `admin` |
| 19 | Server re-validation | POST delete with a wrong confirm_key (devtools) | refused; nothing sent | ✅ 20:49 — `localhostx`, `LOCALHOST`, empty key, or acknowledge=0 → "Type the store key exactly and tick the box to confirm."; another view's identity → "not linked to this store view"; no DELETE in the log |
| 20 | Cancel | Cancel deletion | mock active; view stays disabled until re-enabled | ✅ 20:51:27 in the browser (banner button): mock `active`, banner gone, A still `enabled=0`; log `deletion_cancelled` by `admin` |
| 21 | Key change keeps old store | Change A's key, sync | old key listed as previous; deleting it leaves A syncing | ✅ 20:51 — A key → `shop-a.example`: save warning names "localhost"; Danger zone lists `localhost (previous, active)` immediately, before any sync; after sync `shop-a.example (current)` + `localhost (previous)`; deleting `localhost` → pending, A stays enabled and syncing (CLI push 20:53 after fix 9a6f80a) |
| 22 | Connection mismatch | Switch to a second API key | clear/delete/cancel refused with the connection message | ❌ (design) 20:53 — after switching to `ae_other`: **delete** and **cancel** refused with ""shop-a.example" was synced through a different AnswerEng connection (engine …, account acct_e1e3811…). Reconnect with that connection to manage it, or Forget it here."; no request sent. **Clear** is refused too and sends nothing, but with "This store view has no current AnswerEng store to clear.": Clear's target is the view's *current* identity, and an identity from another connection can never be current (spec §7.1 roles), so the connection-check message is unreachable for Clear. Whether Clear should target the key's identity on the old connection (to show that message) is not settled by the spec. Reconnecting (key back to `ae_test`) made cancel work again |
| 23 | Forget | Forget a previous store | removed locally; mock receives nothing | ✅ 20:52:46 — Forget `localhost` (previous, pending): association and identity row removed, no non-GET request, mock still lists it pending; Forget on the current identity is refused |
| 24 | Shared key across views | Disable A; enable B with A's key; try clear/delete from A | refused: active for B | ✅ 20:53 — one identity `shop-a.example` with views 1,2; A's Danger zone shows "also active for Second View (second_view)"; A clear and delete → ""shop-a.example" is the active AnswerEng store for store view Second View (second_view). Disable that view or change its store key first."; only B's push reached the mock |
| 25 | CLI | `bin/magento answereng:sync --store=<code>`; unknown code | per-view messages; unknown code exits non-zero | ✅ after fix 9a6f80a — first run failed for every view ("Reading the catalog failed", log: Area code is not set) (❌, fixed). Now: `--store=default` → "Default Store View (default): Sent 180 products to AnswerEng." exit 0; no option → one line per enabled view, exit 0; `--store=nope` → "Store view "nope" does not exist or is not enabled for AnswerEng." exit 1; throttled → message, exit 1 |
| 26 | Clean uninstall | `composer remove` + `bin/magento module:uninstall` route per dev-environment.md | tables and answereng/* config rows gone | ✅ after fix a82581e — `module:uninstall` (no flag) removes code only, data kept (documented Magento behaviour). `module:uninstall --remove-data` first left 4 tables + 8 config rows (❌: no `setup_module` row, so `Setup\Uninstall` never ran; fixed). After the fix: 0 tables, 0 `answereng/%` rows, no `setup_module` row, package removed from composer.json and config.php. Module reinstalled afterwards |
| 27 | Panel at every scope | Load the section at default, website and store-view scope | Renders at all three; website scope lists only that website's views; no exception.log entries | ✅ default lists A and B; website 1 lists only A, website 2 only B; store views render the status panel; `exception.log` empty (truncated before the run; re-checked at the end) |
| 28 | Routes reject GET / missing form key | GET each answereng/action/* route; POST without a form key | Rejected | ✅ GET to syncnow, testconnection, clear, delete, cancel, forget → 404; POST without or with a wrong form key → 302 to the dashboard; no engine write, registry unchanged |
| 29 | Config-only role | Role with config but without `::sync` | Access denied from SyncNow/Clear controllers, not just hidden buttons | ✅ role "AnswerEng config only" (`aeconfig`): Sync now/Clear/Danger zone hidden (Test connection shown); direct POSTs with a valid form key → 403 for syncnow and clear; testconnection allowed (302 back) |
| 30 | Clear on a disabled view | Clear on A while disabled | DELETE /stores/<key>/catalog reaches the mock | ✅ 20:51:43 — A `enabled=0`, Clear button rendered; `DELETE /stores/localhost/catalog` 200; log `catalog_cleared` by `admin` |
| 31 | Pending-deletion banner | Banner with purge date and Cancel; role without delete_store | Date (UTC) + Cancel; no Cancel without delete_store | ✅ admin: "AnswerEng store "localhost" is scheduled for deletion on 2026-10-15 20:50 UTC. Cancel deletion"; `aesync`: same text, no Cancel button |
| 32 | Unknown ids | ?store=999 in the panel URL; store=999 POSTed to an action; also ?website=999 | Clean message/fallback, not an exception page; record ?website=999 | ✅ for the module: store=999 (and a missing store) POSTed to syncnow, clear, testconnection, delete → "Unknown store view. Choose a store view and try again.", no engine call. The panel URL `…/section/answereng/store/999/` and `…/website/999/` give Magento's NoSuchEntityException page, but so does `…/section/general/store/999/` and `…/website/999/`: core's config scope resolver throws before any module block renders, so the module's own fallback (and the `storeIdsForWebsites` suspect) is unreachable through the page |
| 33 | Delete gets 404 for the current store | Mock loses the store (restart); delete the view's current store | Record identity status, whether the view stays enabled, whether the next sync re-creates the store | 🔎 observed 20:53:46 — B's current `shop-a.example`: "AnswerEng has no store "shop-a.example" (it may already have been deleted)."; identity → `deleted` (panel "current, deleted"); B stays **enabled**; the next cron push (20:53:48) **re-created** the store in the mock and the identity flipped back to `active`. Open design question, not changed |

Tally: ✅ 31 (4 of them after fixes made during this run), ❌ 1 (row 22, needs a design
decision), ⚠️ 0, 🔎 1 observe-only (row 33).

## Other observations (not checklist rows)

- Spec §4/§6 also ask for persistent admin *system messages* for `too_many_products` and
  `exhausted`; none exist (the plan never scheduled them). The panel now states both.
- Config saves do not delete the summary cache entry or snapshot of a changed fingerprint
  (spec §7). Never shown for another identity, but a 15-minute cache entry survives a
  key change and change back.
- Saving a store key that already exists remotely (B taking A's synced key) still warns
  that it "registers a NEW AnswerEng store".
- Sync now / CLI sync-log rows have `admin_user` empty (only delete/cancel/clear/forget
  record it); `answereng.log` request lines carry no admin username (known deferral).
- An empty-catalog view re-sends the idempotent `DELETE /stores/{key}/catalog` on every
  selected run (known deferral; seen at 20:40:43).
- Core admin noise unrelated to the module: Braintree `system.js` TypeError in the
  console, sample-data media-gallery exceptions during `setup:upgrade`.
