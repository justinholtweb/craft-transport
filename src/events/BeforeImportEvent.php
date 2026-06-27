<?php

namespace justinholtweb\transport\events;

use justinholtweb\transport\models\TransportPackage;
use yii\base\Event;

/**
 * Raised after a package is opened and validated but before any element is imported, so
 * listeners can inspect the package or cancel the import (set {@see $isValid} to false).
 */
class BeforeImportEvent extends Event
{
    /** @var TransportPackage The package about to be imported. */
    public TransportPackage $package;

    /** @var bool Whether this is a dry run. */
    public bool $dryRun = false;

    /** @var bool Set to false to cancel the import. */
    public bool $isValid = true;
}
