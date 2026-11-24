# Changelog

## 0.2.0 — unreleased

Run `bin/magento setup:upgrade` after updating. See "Upgrading" in the README.

### Added
- **Catalog › AnswerEng › Status & AI Visibility:** a status page per store
  view. It shows sync status, Test connection, Sync now, Clear catalog, the
  Danger zone (delete with a 7-day undo), recent activity and AI visibility
  results:
  - overall visibility with its trend
  - presence per engine and model
  - share of voice
- **Drafted fixes:** fixes are grouped by problem. Each card shows the buyer
  prompt, what is missing from the product data, and each draft (Q&A, product
  description, structured data, attribute) ready to copy. The count is the
  engine's full total, and the list shows the 100 newest.
- **Mark as applied / Undo:** fixes can be marked applied from Magento, with an
  Applied list. This needs the new permission `Fullspec_AnswerEng::fixes`.
- **Auto-sync switch:** Stores › Configuration › Catalog › AnswerEng › Catalog
  sync. Turning it off stops syncing on product and settings changes; the
  nightly sync, retries, Sync now and the CLI still run.
- **Settings page:** onboarding steps, a "What data leaves this site" list, and
  a link to the status page.
- **Full results link:** a link to the store on app.answereng.io (shown for the
  production engine only).
- **Local store key warning:** a warning when a store key looks local (such as
  `localhost`), with the reason.

### Changed
- **Status moved:** status and store actions moved from Stores › Configuration
  to the new Catalog › AnswerEng page. The ACL now has
  `Fullspec_AnswerEng::status` with `sync`, `delete_store` and `fixes` under
  it. `Fullspec_AnswerEng::config` covers the settings only.
- **Read budget:** results load live from AnswerEng within a shared 20-second
  budget. When AnswerEng is slow or unreachable, the page shows the last
  stored results marked as stale.
- **Out of sync:** a synced store that is missing in AnswerEng now shows as
  "Out of sync".

### Upgrade
- **Schema:** `answereng_sync_state` gets `snapshot_fixes_json` and
  `snapshot_applied_json`, and `snapshot_json` widens to `mediumtext`.
- **Permissions:** admin roles that had an AnswerEng permission in 0.1.0 get
  `Fullspec_AnswerEng::status`, so their users keep Sync now, Clear and Delete.

## 0.1.0 — 2026-11-05

The first release: catalog sync per store view, the status panel on the
settings page, Test connection, Sync now, Clear, and store delete with a 7-day
undo.
