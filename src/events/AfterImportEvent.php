<?php

namespace justinholtweb\transport\events;

use justinholtweb\transport\models\TransportPackage;
use justinholtweb\transport\models\TransportReport;
use yii\base\Event;

/**
 * Raised after an import finishes (whether it succeeded, failed, or was a dry run).
 */
class AfterImportEvent extends Event
{
    /** @var TransportPackage The imported package. */
    public TransportPackage $package;

    /** @var array{status:string,created:int,updated:int,skipped:int,failed:int,errors:string[]} The import result. */
    public array $result = [];

    /** @var TransportReport|null Detailed per-element outcome of the import. */
    public ?TransportReport $report = null;

    /** @var bool Whether this was a dry run. */
    public bool $dryRun = false;
}
