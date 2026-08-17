<?php

namespace justinholtweb\transport\tests\unit;

use justinholtweb\transport\models\TransportReport;
use PHPUnit\Framework\TestCase;

/**
 * The run report: per-action totals, per-type breakdown, detail rows, the detail cap,
 * and round-tripping through the history record's JSON column.
 */
class TransportReportTest extends TestCase
{
    private function element(string $uid, string $key = 'entries', string $title = 'Title'): array
    {
        return [
            'uid' => $uid,
            'key' => $key,
            'type' => 'craft\\elements\\Entry',
            'sites' => ['default' => ['title' => $title]],
        ];
    }

    public function testCountsAggregatePerAction(): void
    {
        $report = new TransportReport();
        $report->record(TransportReport::ACTION_CREATED, $this->element('a'));
        $report->record(TransportReport::ACTION_CREATED, $this->element('b'));
        $report->record(TransportReport::ACTION_UPDATED, $this->element('c'));
        $report->record(TransportReport::ACTION_SKIPPED, $this->element('d'), 'no site');

        $this->assertSame(2, $report->countOf(TransportReport::ACTION_CREATED));
        $this->assertSame(1, $report->countOf(TransportReport::ACTION_UPDATED));
        $this->assertSame(1, $report->countOf(TransportReport::ACTION_SKIPPED));
        $this->assertSame(0, $report->countOf(TransportReport::ACTION_FAILED));
        $this->assertSame(4, $report->total());
    }

    public function testCountsAreBrokenDownByPackageKey(): void
    {
        $report = new TransportReport();
        $report->record(TransportReport::ACTION_CREATED, $this->element('a', 'entries'));
        $report->record(TransportReport::ACTION_UPDATED, $this->element('b', 'entries'));
        $report->record(TransportReport::ACTION_CREATED, $this->element('c', 'categories'));

        $this->assertSame(
            ['entries' => ['created' => 1, 'updated' => 1], 'categories' => ['created' => 1]],
            $report->getCountsByKey()
        );
    }

    public function testItemsCarryTitleTypeAndDetail(): void
    {
        $report = new TransportReport();
        $report->record(TransportReport::ACTION_SKIPPED, $this->element('a', 'entries', 'Lonely'), 'no matching site');

        $items = $report->itemsFor(TransportReport::ACTION_SKIPPED);
        $this->assertCount(1, $items);
        $this->assertSame('Lonely', $items[0]['title']);
        $this->assertSame('entries', $items[0]['key']);
        $this->assertSame('no matching site', $items[0]['detail']);
        $this->assertSame([], $report->itemsFor(TransportReport::ACTION_CREATED));
    }

    public function testTitleFallsBackToUidWhenNoSiteHasOne(): void
    {
        $this->assertSame('the-uid', TransportReport::titleOf(['uid' => 'the-uid', 'sites' => []]));
    }

    public function testDetailRowsAreCappedButCountsStayExact(): void
    {
        $report = new TransportReport();
        for ($i = 0; $i < TransportReport::MAX_ITEMS + 5; $i++) {
            $report->record(TransportReport::ACTION_CREATED, $this->element("uid-$i"));
        }

        $this->assertSame(TransportReport::MAX_ITEMS + 5, $report->countOf(TransportReport::ACTION_CREATED));
        $this->assertCount(TransportReport::MAX_ITEMS, $report->items);
        $this->assertTrue($report->truncated);
    }

    public function testErrorsAreCappedAndOverflowIsCounted(): void
    {
        $report = new TransportReport();
        for ($i = 0; $i < TransportReport::MAX_ERRORS + 3; $i++) {
            $report->recordError("problem $i");
        }

        $this->assertCount(TransportReport::MAX_ERRORS, $report->errors);
        $this->assertSame(3, $report->errorOverflow);
    }

    public function testEndMarksFailedWhenErrorsWereRecorded(): void
    {
        $report = new TransportReport();
        $report->begin();
        $report->recordError('boom');
        $report->end();

        $this->assertSame(TransportReport::STATUS_FAILED, $report->status);
        $this->assertFalse($report->isSuccessful());
    }

    public function testEndMarksDryRunWhenSimulating(): void
    {
        $report = new TransportReport();
        $report->dryRun = true;
        $report->end();

        $this->assertSame(TransportReport::STATUS_DRY_RUN, $report->status);
    }

    public function testEndAcceptsAnExplicitStatus(): void
    {
        $report = new TransportReport();
        $report->end(TransportReport::STATUS_CANCELLED);

        $this->assertSame(TransportReport::STATUS_CANCELLED, $report->status);
    }

    public function testSummaryListsEveryAction(): void
    {
        $report = new TransportReport();
        $report->record(TransportReport::ACTION_CREATED, $this->element('a'));

        $this->assertSame('created 1, updated 0, skipped 0, failed 0', $report->summary());
    }

    public function testLegacyResultKeepsTheOldImportShape(): void
    {
        $report = new TransportReport();
        $report->record(TransportReport::ACTION_CREATED, $this->element('a'));
        $report->record(TransportReport::ACTION_UPDATED, $this->element('b'));
        $report->recordError('nope');
        $report->end();

        $this->assertSame([
            'status' => 'failed',
            'created' => 1,
            'updated' => 1,
            'skipped' => 0,
            'failed' => 0,
            'errors' => ['nope'],
        ], $report->toLegacyResult());
    }

    public function testStorageRoundTripPreservesCountsItemsAndErrors(): void
    {
        $report = new TransportReport();
        $report->direction = 'export';
        $report->packageName = 'blog.zip';
        $report->dryRun = true;
        $report->record(TransportReport::ACTION_CREATED, $this->element('a', 'entries', 'One'));
        $report->record(TransportReport::ACTION_SKIPPED, $this->element('b', 'assets', 'Two'), 'missing file');
        $report->recordError('a problem');
        $report->end();

        $restored = TransportReport::fromStorageArray(
            json_decode(json_encode($report->toStorageArray()), true)
        );

        $this->assertSame('export', $restored->direction);
        $this->assertSame('blog.zip', $restored->packageName);
        $this->assertTrue($restored->dryRun);
        $this->assertSame($report->getCounts(), $restored->getCounts());
        $this->assertSame($report->getCountsByKey(), $restored->getCountsByKey());
        $this->assertSame($report->items, $restored->items);
        $this->assertSame(['a problem'], $restored->errors);
        $this->assertSame(0, $restored->errorOverflow);
        $this->assertSame($report->status, $restored->status);
    }

    public function testFromStorageArrayToleratesLegacyRowsWithNoData(): void
    {
        $restored = TransportReport::fromStorageArray([]);

        $this->assertSame(0, $restored->total());
        $this->assertSame([], $restored->items);
    }
}
