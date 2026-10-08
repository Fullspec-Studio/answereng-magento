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
