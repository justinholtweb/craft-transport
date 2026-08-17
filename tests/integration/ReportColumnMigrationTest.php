<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use Craft;
use justinholtweb\transport\migrations\m260816_120000_add_report_column;
use justinholtweb\transport\records\ImportHistory;

/**
 * The 1.0.0 → 1.1.0 update path.
 *
 * Fresh installs get the report column from the install migration; sites updating from
 * 5.0.x get it from this migration, which is the path the install migration can't cover.
 */
final class ReportColumnMigrationTest extends TransportTestCase
{
    private function columnExists(): bool
    {
        Craft::$app->getDb()->getSchema()->refresh();

        return Craft::$app->getDb()->columnExists(ImportHistory::TABLE, 'report');
    }

    private function migration(): m260816_120000_add_report_column
    {
        return new m260816_120000_add_report_column(['db' => Craft::$app->getDb()]);
    }

    public function testMigrationAddsTheColumnToAPre110Table(): void
    {
        // Stand the table back up the way 5.0.x left it.
        $this->migration()->safeDown();
        self::assertFalse($this->columnExists(), 'precondition: the column is gone');

        self::assertTrue($this->migration()->safeUp());
        self::assertTrue($this->columnExists(), 'updating adds the report column');
    }

    public function testMigrationIsSafeToRunTwice(): void
    {
        self::assertTrue($this->migration()->safeUp());
        self::assertTrue($this->migration()->safeUp());
        self::assertTrue($this->columnExists());
    }

    public function testReportsAreWritableOnceMigrated(): void
    {
        $this->migration()->safeUp();

        $this->makeCategory('Post Migration', 'migration-report');
        $path = $this->export(['categories'], 'migration-report');
        $report = $this->plugin()->import->run($path);

        $stored = ImportHistory::findOne(['id' => $report->historyId])->getRunReport();

        self::assertNotNull($stored, 'the migrated column round-trips a run report');
        self::assertSame($report->getCounts(), $stored->getCounts());
    }
}
