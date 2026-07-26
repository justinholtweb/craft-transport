<?php
/**
 * PHPUnit bootstrap for Transport's pure-logic unit tests.
 *
 * These tests exercise the plugin's Craft-free logic (dependency graph, resolver,
 * selective merge, package/diff/config models) without a full Craft/database runtime.
 * Requiring Yii's bootstrap registers the global `Yii` class and DI container, which is
 * all that Craft model validation needs.
 *
 * Set TRANSPORT_AUTOLOAD to point at an alternate autoloader (e.g. a host Craft
 * project's vendor/autoload.php) when running outside a standalone `composer install`.
 */

declare(strict_types=1);

error_reporting(E_ALL);

$autoload = getenv('TRANSPORT_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Autoloader not found at $autoload. Run `composer install` or set TRANSPORT_AUTOLOAD.\n");
    exit(1);
}

require $autoload;
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
