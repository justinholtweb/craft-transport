<?php

namespace justinholtweb\transport\queue;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\transport\models\ExportConfig;
use justinholtweb\transport\Plugin;

/**
 * Exports content to a Transport package in the background.
 */
class ExportJob extends BaseJob
{
    /** @var array Serialized {@see ExportConfig} attributes. */
    public array $config = [];

    public function execute($queue): void
    {
        $this->setProgress($queue, 0.1, Craft::t('transport', 'Gathering elements…'));

        $config = new ExportConfig();
        $config->setAttributes($this->config, false);

        $path = Plugin::getInstance()->export->export($config);

        $this->setProgress($queue, 1, Craft::t('transport', 'Done'));
        Craft::info("Queued export wrote package: $path", 'transport');
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('transport', 'Exporting Transport package');
    }
}
