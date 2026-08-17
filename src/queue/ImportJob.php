<?php

namespace justinholtweb\transport\queue;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\transport\models\TransportReport;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\progress\QueueProgress;
use justinholtweb\transport\records\ImportHistory;
use justinholtweb\transport\services\Import;
use Throwable;

/**
 * Imports a Transport package in the background, so large imports can't hit a request
 * timeout, and emails the initiating user the report when it finishes.
 */
class ImportJob extends BaseJob
{
    public string $path = '';
    public bool $dryRun = false;
    public array $options = [];

    /** @var bool Whether to email the initiating user when the import finishes. */
    public bool $notify = true;

    public function execute($queue): void
    {
        $progress = new QueueProgress(
            fn(float $progress, ?string $label) => $this->setProgress(
                $queue,
                $progress,
                $label === null ? null : Craft::t('transport', $label)
            ),
            self::stages()
        );

        try {
            $report = Plugin::getInstance()->import->run($this->path, $this->dryRun, [], $this->options, $progress);
        } catch (Throwable $e) {
            // Tell the person waiting on this import that it died, then let the queue
            // record the failure as usual.
            $this->notifyOfFailure($e);
            throw $e;
        }

        if ($report->errors) {
            Craft::warning('Queued import finished with errors: ' . implode('; ', $report->errors), 'transport');
        }

        if ($this->notify) {
            Plugin::getInstance()->notifier->reportFinished($report);
        }
    }

    private function notifyOfFailure(Throwable $e): void
    {
        if (!$this->notify) {
            return;
        }

        $report = new TransportReport();
        $report->direction = ImportHistory::DIRECTION_IMPORT;
        $report->packageName = basename($this->path);
        $report->userId = $this->options['userId'] ?? null;
        $report->recordError($e->getMessage());
        $report->end(TransportReport::STATUS_FAILED);

        Plugin::getInstance()->notifier->reportFinished($report);
    }

    /**
     * Where each {@see Import} stage lands on the job's 0–1 progress bar.
     *
     * @return array<int, array{0:string,1:float}>
     */
    public static function stages(): array
    {
        return [
            [Import::STAGE_READ, 0.1],
            [Import::STAGE_SNAPSHOT, 0.25],
            [Import::STAGE_IMPORT, 0.95],
            [Import::STAGE_FINISH, 1.0],
        ];
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('transport', 'Importing Transport package');
    }
}
