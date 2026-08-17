<?php

namespace justinholtweb\transport\tests\unit;

use craft\console\Controller;
use justinholtweb\transport\console\ReportPrinter;
use justinholtweb\transport\models\TransportReport;
use justinholtweb\transport\progress\ConsoleProgress;
use justinholtweb\transport\records\ImportHistory;
use PHPUnit\Framework\TestCase;

/**
 * What a console run prints: the ongoing status lines and the closing report.
 */
class ReportPrinterTest extends TestCase
{
    private string $output = '';

    /**
     * A console controller that collects everything written to it.
     */
    private function controller(): Controller
    {
        $this->output = '';

        $controller = $this->createMock(Controller::class);
        $collect = function (string $string, ...$args): int {
            $this->output .= $string;
            return strlen($string);
        };

        $controller->method('stdout')->willReturnCallback($collect);
        $controller->method('stderr')->willReturnCallback($collect);

        return $controller;
    }

    private function report(string $direction = ImportHistory::DIRECTION_IMPORT): TransportReport
    {
        $report = new TransportReport();
        $report->direction = $direction;
        $report->packageName = 'blog.zip';
        $report->record(TransportReport::ACTION_CREATED, [
            'uid' => 'a', 'key' => 'entries', 'sites' => ['default' => ['title' => 'New Post']],
        ]);
        $report->record(TransportReport::ACTION_UPDATED, [
            'uid' => 'b', 'key' => 'entries', 'sites' => ['default' => ['title' => 'Old Post']],
        ]);
        $report->record(TransportReport::ACTION_SKIPPED, [
            'uid' => 'c', 'key' => 'assets', 'sites' => ['default' => ['title' => 'Orphan']],
        ], 'no matching site');
        $report->end();

        return $report;
    }

    public function testPrintsTotalsPerOutcome(): void
    {
        (new ReportPrinter($this->controller()))->print($this->report());

        $this->assertStringContainsString('Added', $this->output);
        $this->assertStringContainsString('Updated', $this->output);
        $this->assertStringContainsString('Skipped', $this->output);
        $this->assertStringContainsString('Failed', $this->output);
    }

    public function testPrintsEveryElementBehindAnOutcome(): void
    {
        (new ReportPrinter($this->controller()))->print($this->report());

        $this->assertStringContainsString('New Post (entries)', $this->output);
        $this->assertStringContainsString('Old Post (entries)', $this->output);
        $this->assertStringContainsString('Orphan (assets) — no matching site', $this->output);
    }

    public function testPrintsPerTypeBreakdown(): void
    {
        (new ReportPrinter($this->controller()))->print($this->report());

        $this->assertStringContainsString('By type', $this->output);
        $this->assertMatchesRegularExpression('/entries\s+added 1, updated 1/', $this->output);
    }

    public function testExportRunsCallTheirCreatedRowsExported(): void
    {
        (new ReportPrinter($this->controller()))->print($this->report(ImportHistory::DIRECTION_EXPORT));

        $this->assertStringContainsString('Exported', $this->output);
        $this->assertStringNotContainsString('Added', $this->output);
    }

    public function testTrimsLongListsAndSaysHowManyWereHidden(): void
    {
        $report = new TransportReport();
        for ($i = 0; $i < 5; $i++) {
            $report->record(TransportReport::ACTION_CREATED, [
                'uid' => "uid-$i", 'key' => 'entries', 'sites' => ['default' => ['title' => "Post $i"]],
            ]);
        }

        (new ReportPrinter($this->controller(), 2))->print($report);

        $this->assertStringContainsString('Post 0', $this->output);
        $this->assertStringNotContainsString('Post 4', $this->output);
        $this->assertStringContainsString('…and 3 more', $this->output);
    }

    public function testPrintsErrors(): void
    {
        $report = new TransportReport();
        $report->recordError('Entry "Broken" [default]: title cannot be blank');
        $report->end();

        (new ReportPrinter($this->controller()))->print($report);

        $this->assertStringContainsString('Errors', $this->output);
        $this->assertStringContainsString('title cannot be blank', $this->output);
    }

    public function testConsoleProgressAnnouncesUncountableStages(): void
    {
        $progress = new ConsoleProgress($this->controller());
        $progress->start('Gathering elements');
        $progress->note('12 elements found.');
        $progress->finish('Done.');

        $this->assertStringContainsString('Gathering elements', $this->output);
        $this->assertStringContainsString('12 elements found.', $this->output);
        $this->assertStringContainsString('Done.', $this->output);
    }

    public function testConsoleProgressEchoesEachElementWhenVerbose(): void
    {
        $progress = new ConsoleProgress($this->controller(), true);
        $progress->start('Importing elements');
        $progress->advance('A Post');

        $this->assertStringContainsString('A Post', $this->output);
    }

    public function testConsoleProgressStaysQuietPerElementByDefault(): void
    {
        $progress = new ConsoleProgress($this->controller());
        $progress->start('Importing elements');
        $progress->advance('A Post');

        $this->assertStringNotContainsString('A Post', $this->output);
    }
}
