<?php

namespace justinholtweb\transport\queue;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\transport\models\ExportConfig;
use justinholtweb\transport\models\TransportReport;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\progress\QueueProgress;
use justinholtweb\transport\records\ImportHistory;
use justinholtweb\transport\services\Export;
use Throwable;

/**
 * Exports content to a Transport package in the background, so large exports can't hit
 * a request timeout, and emails the initiating user the report when it finishes.
 */
class ExportJob extends BaseJob
{
    /** @var array Serialized {@see ExportConfig} attributes. */
    public array $config = [];

    /** @var bool Whether to email the initiating user when the export finishes. */
    public bool $notify = true;

    public function execute($queue): void
    {
        $config = new ExportConfig();
        $config->setAttributes($this->config, false);

        $progress = new QueueProgress(
            fn(float $progress, ?string $label) => $this->setProgress(
                $queue,
                $progress,
                $label === null ? null : Craft::t('transport', $label)
            ),
            self::stages()
        );

        try {
            $report = Plugin::getInstance()->export->run($config, $progress);
        } catch (Throwable $e) {
            // Tell the person waiting on this export that it died, then let the queue
            // record the failure as usual.
            $this->notifyOfFailure($config->userId, $e);
            throw $e;
        }

        Craft::info(sprintf(
            'Queued export %s [%s]: %s',
            $report->packageName,
            $report->status,
            $report->summary()
        ), 'transport');

        if ($this->notify) {
            Plugin::getInstance()->notifier->reportFinished($report);
        }
    }

    private function notifyOfFailure(?int $userId, Throwable $e): void
    {
        if (!$this->notify) {
            return;
        }

        $report = new TransportReport();
        $report->direction = ImportHistory::DIRECTION_EXPORT;
        $report->userId = $userId;
        $report->recordError($e->getMessage());
        $report->end(TransportReport::STATUS_FAILED);

        Plugin::getInstance()->notifier->reportFinished($report);
    }

    /**
     * Where each {@see Export} stage lands on the job's 0–1 progress bar.
     *
     * @return array<int, array{0:string,1:float}>
     */
    public static function stages(): array
    {
        return [
            [Export::STAGE_GATHER, 0.15],
            [Export::STAGE_SERIALIZE, 0.75],
            [Export::STAGE_FILES, 0.9],
            [Export::STAGE_WRITE, 1.0],
        ];
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('transport', 'Exporting Transport package');
    }
}
