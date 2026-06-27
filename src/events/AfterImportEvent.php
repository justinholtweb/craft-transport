<?php

namespace justinholtweb\transport\events;

use justinholtweb\transport\models\TransportPackage;
use yii\base\Event;

/**
 * Raised after an import finishes (whether it succeeded, failed, or was a dry run).
 */
class AfterImportEvent extends Event
{
    /** @var TransportPackage The imported package. */
    public TransportPackage $package;

    /** @var array{status:string,created:int,updated:int,skipped:int,errors:string[]} The import result. */
    public array $result = [];

    /** @var bool Whether this was a dry run. */
    public bool $dryRun = false;
}
