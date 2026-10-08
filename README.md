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
