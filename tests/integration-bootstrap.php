<?php
/**
 * Bootstrap for Transport's integration tests.
 *
 * Integration tests exercise the full export/import pipeline against a real, booted
 * Craft application, so they must run from within (or be pointed at) a Craft project.
 * Set CRAFT_BASE_PATH to the project root; it defaults to /var/www/html (the DDEV path
 * used by the development test site).
 *
 * Run:  CRAFT_BASE_PATH=/path/to/craft-project vendor/bin/phpunit -c phpunit-integration.xml
 */

// PHPUnit + the plugin's own classes (the phpunit binary has already required this, but
// be explicit so the file is usable standalone).
require __DIR__ . '/../vendor/autoload.php';

$projectRoot = getenv('CRAFT_BASE_PATH') ?: '/var/www/html';

if (!is_file($projectRoot . '/bootstrap.php')) {
    fwrite(STDERR, "Integration tests require a Craft project. Set CRAFT_BASE_PATH to its root.\n");
    exit(1);
}

// Boot the project's Craft (defines CRAFT_BASE_PATH/CRAFT_VENDOR_PATH, loads .env).
require $projectRoot . '/bootstrap.php';

/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
