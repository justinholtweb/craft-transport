<?php
/**
 * PHPUnit bootstrap. Loads the Composer autoloader so Transport's classes (and their
 * Craft/Yii base classes) are available to the pure-logic unit tests.
 *
 * Set TRANSPORT_AUTOLOAD to point at an alternate autoloader (e.g. a host Craft project's
 * vendor/autoload.php) when running outside a standalone `composer install`.
 */
$autoload = getenv('TRANSPORT_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Autoloader not found at $autoload. Run `composer install` or set TRANSPORT_AUTOLOAD.\n");
    exit(1);
}

require $autoload;
