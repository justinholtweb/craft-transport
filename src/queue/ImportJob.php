<?php

namespace justinholtweb\transport\queue;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\transport\Plugin;

/**
 * Imports a Transport package in the background.
 */
class ImportJob extends BaseJob
{
    public string $path = '';
    public bool $dryRun = false;
    public array $options = [];

    public function execute($queue): void
    {
        $this->setProgress($queue, 0.1, Craft::t('transport', 'Reading package…'));

        $result = Plugin::getInstance()->import->importPackage($this->path, $this->dryRun, [], $this->options);

        $this->setProgress($queue, 1, Craft::t('transport', 'Done'));

        if ($result['errors']) {
            Craft::warning('Queued import finished with errors: ' . implode('; ', $result['errors']), 'transport');
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('transport', 'Importing Transport package');
    }
}
