# Tests

Transport has two independent test suites.

## Unit tests (PHPUnit)

Pure-logic tests with no booted Craft application: the dependency graph and resolver,
the selective-merge engine, and the package / diff / config / settings models. They only
need Composer's autoloader plus Yii's bootstrap (for Craft model validation), so they're
fast and CI-friendly.

```bash
composer install
composer test          # or: vendor/bin/phpunit
```

## Integration tests (Craft + Codeception)

End-to-end tests that exercise the real export → import pipeline against a **booted Craft
application** backed by a throwaway `test` database: UID-based serialization, field
portability (relations, Matrix), dependency ordering, selective merge, snapshot/rollback,
asset file transfer, and pre-flight validation.

Each test builds the schema it needs (sections, category/tag groups, fields, volumes) on
demand and runs inside a transaction, so nothing leaks between tests or persists after the
run.

### Running with DDEV (recommended)

The repo ships a DDEV config (`.ddev/config.yaml`, PHP 8.2 + MariaDB 10.11). From the
project root:

```bash
ddev start
ddev exec 'mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS test"'   # first run only
ddev exec vendor/bin/codecept run integration
```

Test-database credentials live in `tests/.env` and target DDEV's `db` host by default.

### Running elsewhere

Any environment with PHP 8.2+ and a MySQL/MariaDB `test` database works. Point
`tests/.env` at your database and run `vendor/bin/codecept run integration`.

## Layout

```
tests/
  bootstrap.php                 PHPUnit bootstrap (autoloader + Yii)
  _bootstrap.php                Codeception bootstrap (boots Craft)
  .env                          test DB credentials (DDEV defaults)
  integration.suite.yml         Craft module config (transactional, self-cleaning)
  _craft/                       throwaway Craft config/storage for the test app
  _support/                     Codeception actor classes
  unit/                         PHPUnit tests
  integration/                  Codeception tests
    TransportTestCase.php       shared schema + element fixtures
```

`codeception.yml` lives at the project root.
