<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use justinholtweb\transport\models\ExportConfig;
use justinholtweb\transport\models\TransportReport;
use justinholtweb\transport\progress\ProgressInterface;
use justinholtweb\transport\records\ImportHistory;
use justinholtweb\transport\services\Export;
use justinholtweb\transport\services\Import;

/**
 * The detailed run report produced by exports and imports: per-element outcomes, its
 * persistence on the history row, and the progress a console or queue run observes.
 */
final class ReportingTest extends TransportTestCase
{
    /**
     * Records every stage and step a run reports, so tests can assert on progress
     * without a terminal or a queue.
     */
    private function spy(): ProgressInterface
    {
        return new class implements ProgressInterface {
            /** @var array<int, array{label:string,total:int}> */
            public array $stages = [];
            /** @var string[] */
            public array $steps = [];
            /** @var string[] */
            public array $notes = [];

            public function start(string $label, int $total = 0): void
            {
                $this->stages[] = ['label' => $label, 'total' => $total];
            }

            public function advance(?string $label = null, int $step = 1): void
            {
                $this->steps[] = (string)$label;
            }

            public function note(string $message): void
            {
                $this->notes[] = $message;
            }

            public function finish(?string $label = null): void
            {
            }
        };
    }

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------

    public function testExportReportCountsWhatWentIntoThePackage(): void
    {
        $this->makeCategory('First', 'rep-exp-one');
        $this->makeCategory('Second', 'rep-exp-two');

        $config = new ExportConfig();
        $config->packageKeys = ['categories'];
        $config->packageName = 'rep-export';
        $report = $this->plugin()->export->run($config);
        $this->trackPackage($report->packagePath);

        self::assertSame(TransportReport::STATUS_COMPLETED, $report->status);
        self::assertSame(2, $report->countOf(TransportReport::ACTION_CREATED));
        self::assertSame(0, $report->countOf(TransportReport::ACTION_FAILED));
        self::assertSame(['created' => 2], $report->getCountsByKey()['categories']);
        self::assertContains('First', array_column($report->items, 'title'));
        self::assertSame('rep-export.zip', $report->packageName);
    }

    public function testExportReportIsStoredOnItsHistoryRow(): void
    {
        $this->makeCategory('Recorded', 'rep-exp-hist');

        $config = new ExportConfig();
        $config->packageKeys = ['categories'];
        $config->packageName = 'rep-export-history';
        $report = $this->plugin()->export->run($config);
        $this->trackPackage($report->packagePath);

        self::assertNotNull($report->historyId);

        $stored = ImportHistory::findOne(['id' => $report->historyId])->getRunReport();

        self::assertInstanceOf(TransportReport::class, $stored);
        self::assertSame(ImportHistory::DIRECTION_EXPORT, $stored->direction);
        self::assertSame(1, $stored->countOf(TransportReport::ACTION_CREATED));
        self::assertContains('Recorded', array_column($stored->items, 'title'));
    }

    public function testExportReportsEachStageToTheProgressReporter(): void
    {
        $this->makeCategory('Watched', 'rep-exp-progress');

        $config = new ExportConfig();
        $config->packageKeys = ['categories'];
        $config->packageName = 'rep-export-progress';

        $spy = $this->spy();
        $this->trackPackage($this->plugin()->export->run($config, $spy)->packagePath);

        $labels = array_column($spy->stages, 'label');

        self::assertSame([
            Export::STAGE_GATHER,
            Export::STAGE_SERIALIZE,
            Export::STAGE_FILES,
            Export::STAGE_WRITE,
        ], $labels);
        self::assertContains('Watched', $spy->steps);
    }

    // ------------------------------------------------------------------
    // Import
    // ------------------------------------------------------------------

    public function testImportReportSeparatesAddedFromUpdated(): void
    {
        $this->makeCategory('Existing', 'rep-imp-existing');
        $this->makeCategory('Fresh', 'rep-imp-fresh');
        $path = $this->export(['categories'], 'rep-import');

        // Drop one of the two, so re-importing adds it back and updates the other.
        $fresh = $this->findCategory('rep-imp-fresh');
        \Craft::$app->getElements()->deleteElement($fresh, true);

        $report = $this->plugin()->import->run($path);

        self::assertSame(TransportReport::STATUS_COMPLETED, $report->status);
        self::assertSame(1, $report->countOf(TransportReport::ACTION_CREATED));
        self::assertSame(1, $report->countOf(TransportReport::ACTION_UPDATED));

        self::assertSame(
            ['Fresh'],
            array_column($report->itemsFor(TransportReport::ACTION_CREATED), 'title')
        );
        self::assertSame(
            ['Existing'],
            array_column($report->itemsFor(TransportReport::ACTION_UPDATED), 'title')
        );
    }

    public function testImportReportIsStoredOnItsHistoryRow(): void
    {
        $this->makeCategory('Reported', 'rep-imp-hist');
        $path = $this->export(['categories'], 'rep-import-history');

        $report = $this->plugin()->import->run($path);
        $stored = ImportHistory::findOne(['id' => $report->historyId])->getRunReport();

        self::assertInstanceOf(TransportReport::class, $stored);
        self::assertSame(ImportHistory::DIRECTION_IMPORT, $stored->direction);
        self::assertSame($report->getCounts(), $stored->getCounts());
        self::assertContains('Reported', array_column($stored->items, 'title'));
    }

    public function testDryRunReportsWithoutWritingHistory(): void
    {
        $this->makeCategory('Simulated', 'rep-imp-dry');
        $path = $this->export(['categories'], 'rep-import-dry');

        $before = ImportHistory::find()->where(['direction' => 'import'])->count();
        $report = $this->plugin()->import->run($path, true);
        $after = ImportHistory::find()->where(['direction' => 'import'])->count();

        self::assertSame(TransportReport::STATUS_DRY_RUN, $report->status);
        self::assertTrue($report->dryRun);
        self::assertSame(1, $report->countOf(TransportReport::ACTION_UPDATED));
        self::assertNull($report->historyId);
        self::assertSame($before, $after);
    }

    public function testImportReportsEachStageToTheProgressReporter(): void
    {
        $this->makeCategory('Stepped', 'rep-imp-progress');
        $path = $this->export(['categories'], 'rep-import-progress');

        $spy = $this->spy();
        $this->plugin()->import->run($path, false, [], [], $spy);

        $labels = array_column($spy->stages, 'label');

        self::assertSame([
            Import::STAGE_READ,
            Import::STAGE_SNAPSHOT,
            Import::STAGE_IMPORT,
            Import::STAGE_FINISH,
        ], $labels);
        self::assertContains('Stepped (categories)', $spy->steps);
    }

    public function testLegacyImportResultStillCarriesTheOldKeys(): void
    {
        $this->makeCategory('Legacy', 'rep-imp-legacy');
        $path = $this->export(['categories'], 'rep-import-legacy');

        $result = $this->plugin()->import->importPackage($path);

        self::assertSame('completed', $result['status']);
        self::assertSame(1, $result['updated']);
        self::assertArrayHasKey('created', $result);
        self::assertArrayHasKey('skipped', $result);
        self::assertArrayHasKey('errors', $result);
    }

    public function testUnusablePackageStillLandsInHistory(): void
    {
        $path = $this->trackPackage($this->unreadablePackage());

        $report = $this->plugin()->import->run($path);

        self::assertSame(TransportReport::STATUS_FAILED, $report->status);
        self::assertNotEmpty($report->errors);

        // A queued import that never got started must still be accounted for.
        $history = ImportHistory::findOne(['id' => $report->historyId]);
        self::assertNotNull($history);
        self::assertSame(ImportHistory::STATUS_FAILED, $history->status);
        self::assertNotEmpty($history->getRunReport()->errors);
    }

    /**
     * A package written in a format version this build can't read.
     */
    private function unreadablePackage(): string
    {
        $path = $this->plugin()->getSettings()->getResolvedTempPath()
            . DIRECTORY_SEPARATOR . 'rep-unreadable.zip';

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode([
            'version' => \justinholtweb\transport\models\TransportPackage::FORMAT_VERSION + 1,
            'craftVersion' => '5.0.0',
        ]));
        $zip->close();

        return $path;
    }

    public function testCancelledImportReportsCancelledStatus(): void
    {
        $this->makeCategory('Cancelled', 'rep-imp-cancel');
        $path = $this->export(['categories'], 'rep-import-cancel');

        $handler = static function (\justinholtweb\transport\events\BeforeImportEvent $e) {
            $e->isValid = false;
        };
        $this->plugin()->import->on(Import::EVENT_BEFORE_IMPORT, $handler);

        try {
            $report = $this->plugin()->import->run($path);
        } finally {
            $this->plugin()->import->off(Import::EVENT_BEFORE_IMPORT, $handler);
        }

        self::assertSame(TransportReport::STATUS_CANCELLED, $report->status);
        self::assertSame(0, $report->total());
    }
}
