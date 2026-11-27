# AnswerEng for Magento

Magento 2.4 module (`Fullspec_AnswerEng`) that syncs each opted-in store view's
catalog to [AnswerEng](https://answereng.io) and shows sync status, AI
visibility results (trend, per-engine presence, share of voice) and drafted
fixes in admin.

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

## Requirements

Magento Open Source or Adobe Commerce 2.4.6–2.4.8, on the PHP versions each
line supports: 2.4.6 on PHP 8.1–8.2, 2.4.7 on 8.2–8.3, 2.4.8 on 8.3–8.4. CI
tests each of those pairs, plus the committed `composer.lock` (currently
2.4.9 packages) on PHP 8.3 and 8.4.

## Install

The module is on Packagist as
[`fullspec/module-answereng`](https://packagist.org/packages/fullspec/module-answereng):

    composer require fullspec/module-answereng
    bin/magento module:enable Fullspec_AnswerEng && bin/magento setup:upgrade

Without Packagist access, install the zip attached to each
[GitHub release](https://github.com/Fullspec-Studio/answereng-magento/releases)
instead: put it in a folder, run
`composer config repositories.answereng artifact /path/to/that-folder`, then
the same commands.

Then Stores › Configuration › Catalog › AnswerEng: paste the API key, enable
each store view you want analysed, and set brand names. Sync status, AI
visibility and store actions are under Catalog › AnswerEng › Status & AI
Visibility (permission `Fullspec_AnswerEng::status`). Marking a drafted fix
applied needs `Fullspec_AnswerEng::fixes`.

**Upgrading:** run `bin/magento setup:upgrade` after updating the module, and
before using the new version. The results page stores fix lists in two new
snapshot columns on `answereng_sync_state` (and widens the summary snapshot to
`mediumtext`). The status page errors until the schema is applied, and so does
saving AnswerEng settings that change the API key, store key or engine URL.

**From 0.1.0:** Sync now, Clear and Delete moved from Stores › Configuration to
Catalog › AnswerEng. `setup:upgrade` gives every admin role that had an
AnswerEng permission the new `Fullspec_AnswerEng::status` permission, so those
users keep their actions. Two things stay manual, because they widen access:
- `Fullspec_AnswerEng::fixes` (mark drafted fixes applied) is new. Grant it to
  the roles that should use it.
- The Catalog menu entry also needs `Magento_Catalog::catalog`. Roles without
  it reach the page from the settings page link "Open AnswerEng status →".

## Behaviour notes

- Each enabled store view is its own AnswerEng store (key defaults to the
  view's domain, saved once, unique across views).
- Category = the url_key of the deepest assigned category under the view's
  root (Magento has no ordered "first" category path; ties → lowest id).
- Price = the NOT LOGGED IN "from" price from the price index (null if not
  indexed). Description = short description, falling back to description.
- The status page reads results live from AnswerEng. The reads for one page
  view share a 20-second budget; if AnswerEng is slow or unreachable the page
  shows the last stored results labelled stale.
- Stores › Configuration › Catalog › AnswerEng › Catalog sync › Auto-sync
  (default Yes): when No, changes to products and to AnswerEng settings no
  longer trigger a sync for that view. The nightly reconcile, retries, Sync now
  and the CLI still run.
- Settings › What data leaves this site lists what is sent to AnswerEng.
- Store deletion has a 7-day undo window and needs the
  `Fullspec_AnswerEng::delete_store` permission. Roles with "Resource Access:
  All" include it; give limited roles only what they need.

## Local end-to-end

See docs/dev-environment.md (local Magento on 8894 + `dev/mock-engine` on 8895)
and the executed record in docs/integration-checklist.md.

## License

Copyright (c) 2026 Fullspec Studio. Licensed under the Open Software License
version 3.0 (LICENSE.txt) or the Academic Free License version 3.0
(LICENSE_AFL.txt), at your option, the same terms as Magento Open Source
modules.
