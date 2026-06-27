# Tests

Transport has two test suites.

## Unit tests

Pure-logic tests with no Craft dependency (dependency graph, resolver, selective merge,
package model, diff models). Fast, and suitable for CI.

```bash
composer install
vendor/bin/phpunit
```

## Integration tests

End-to-end tests that exercise the full export → import pipeline against a **real, booted
Craft application** — UID-based serialization, round-trip recreation, dependency
ordering, selective merge, and rollback. They create and clean up their own fixtures (a
dedicated category group), so they don't depend on a particular site's schema.

Because they boot Craft, they must run from within a Craft project. Point
`CRAFT_BASE_PATH` at the project root (it defaults to `/var/www/html`, the path used by
the DDEV development site):

```bash
CRAFT_BASE_PATH=/path/to/craft-project vendor/bin/phpunit -c phpunit-integration.xml
```

In the development environment (DDEV), with the plugin mounted at `/var/www/craft-transport`:

```bash
ddev exec 'cd /var/www/craft-transport && CRAFT_BASE_PATH=/var/www/html vendor/bin/phpunit -c phpunit-integration.xml'
```
