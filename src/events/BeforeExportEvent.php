<?php

namespace justinholtweb\transport\events;

use justinholtweb\transport\models\ExportConfig;
use yii\base\Event;

/**
 * Raised before an export runs, so listeners can adjust the configuration (or cancel
 * the export by setting {@see $isValid} to false).
 */
class BeforeExportEvent extends Event
{
    /** @var ExportConfig The export configuration (mutable). */
    public ExportConfig $config;

    /** @var bool Set to false to cancel the export. */
    public bool $isValid = true;
}
