<?php

namespace justinholtweb\transport\tests\unit;

use justinholtweb\transport\progress\NullProgress;
use justinholtweb\transport\progress\QueueProgress;
use PHPUnit\Framework\TestCase;

/**
 * Mapping export/import stages onto a queue job's 0–1 progress bar.
 */
class QueueProgressTest extends TestCase
{
    /** @var array<int, array{0:float,1:?string}> */
    private array $pushed = [];

    private function progress(array $stages): QueueProgress
    {
        $this->pushed = [];

        return new QueueProgress(
            function (float $progress, ?string $label): void {
                $this->pushed[] = [$progress, $label];
            },
            $stages
        );
    }

    private function fractions(): array
    {
        return array_map(static fn(array $p) => $p[0], $this->pushed);
    }

    public function testStagesAdvanceThroughTheirOwnSliceOfTheBar(): void
    {
        $progress = $this->progress([['Reading', 0.2], ['Importing', 1.0]]);

        $progress->start('Reading');
        $progress->finish();
        $progress->start('Importing', 4);
        $progress->advance();
        $progress->advance();

        // Reading runs 0 → 0.2; Importing then covers 0.2 → 1.0, so two of four steps
        // land halfway between them.
        $this->assertSame([0.0, 0.2, 0.2, 0.4, 0.6], $this->fractions());
    }

    public function testFinishPushesTheStageBoundary(): void
    {
        $progress = $this->progress([['Importing', 1.0]]);

        $progress->start('Importing', 3);
        $progress->advance();
        $progress->finish();

        $this->assertSame(1.0, end($this->pushed)[0]);
    }

    public function testAdvanceLabelsCarryPositionWithinTheStage(): void
    {
        $progress = $this->progress([['Importing', 1.0]]);

        $progress->start('Importing', 2);
        $progress->advance('An entry');

        $this->assertSame('Importing (1/2)', end($this->pushed)[1]);
    }

    public function testSubPercentStepsAreThrottledAway(): void
    {
        $progress = $this->progress([['Importing', 1.0]]);

        $progress->start('Importing', 500);
        for ($i = 0; $i < 4; $i++) {
            $progress->advance();
        }

        // 4 of 500 steps is under a percentage point, so only the stage start was pushed.
        $this->assertCount(1, $this->pushed);
    }

    public function testUncountableStagesStillReportTheirStart(): void
    {
        $progress = $this->progress([['Gathering', 0.5]]);

        $progress->start('Gathering');
        $progress->advance('ignored');

        $this->assertSame([[0.0, 'Gathering']], $this->pushed);
    }

    public function testUnknownStagesFallForwardInsteadOfStalling(): void
    {
        $progress = $this->progress([['Known', 0.5]]);

        $progress->start('Known');
        $progress->start('Surprise');

        $this->assertSame([0.0, 0.5], $this->fractions());
        $progress->finish();
        $this->assertSame(0.6, end($this->pushed)[0]);
    }

    public function testNullProgressSwallowsEverything(): void
    {
        $progress = new NullProgress();
        $progress->start('anything', 3);
        $progress->advance('x');
        $progress->note('y');
        $progress->finish('z');

        $this->expectNotToPerformAssertions();
    }
}
