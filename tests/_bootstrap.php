<?php

/**
 * Codeception bootstrap — boots a real Craft application against a throwaway
 * database so Transport's services, records, and the full export → import
 * pipeline can be exercised end to end.
 *
 * The lightweight, Craft-free PHPUnit tests in tests/unit/ use
 * tests/bootstrap.php instead; the two harnesses are independent.
 */

declare(strict_types=1);

use craft\test\TestSetup;

ini_set('date.timezone', 'UTC');

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

// DB credentials + security key for the test app. Committed defaults target ddev.
Dotenv\Dotenv::createUnsafeImmutable(__DIR__)->load();

define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_ROOT_PATH', $root);
define('CRAFT_VENDOR_PATH', $root . '/vendor');
define('CRAFT_CONFIG_PATH', __DIR__ . '/_craft/config');
define('CRAFT_STORAGE_PATH', __DIR__ . '/_craft/storage');
define('CRAFT_TEMPLATES_PATH', __DIR__ . '/_craft/templates');
define('CRAFT_MIGRATIONS_PATH', __DIR__ . '/_craft/migrations');
define('CRAFT_TRANSLATIONS_PATH', __DIR__ . '/_craft/translations');

// TestSetup writes PHP errors here and Craft expects the folders to exist.
foreach (['logs', 'runtime'] as $dir) {
    $path = CRAFT_STORAGE_PATH . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

TestSetup::configureCraft();
