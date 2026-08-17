<?php

namespace justinholtweb\transport\events;

use justinholtweb\transport\models\ExportConfig;
use justinholtweb\transport\models\TransportReport;
use yii\base\Event;

/**
 * Raised after an export package has been written.
 */
class AfterExportEvent extends Event
{
    /** @var ExportConfig The export configuration. */
    public ExportConfig $config;

    /** @var string Absolute path to the written package. */
    public string $path;

    /** @var array<string, array> Serialized elements, keyed by package key. */
    public array $elements = [];

    /** @var TransportReport|null Detailed per-element outcome of the export. */
    public ?TransportReport $report = null;
}
