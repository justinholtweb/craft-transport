<?php

namespace justinholtweb\transport\progress;

use Closure;

/**
 * Feeds a queue job's progress bar, mapping each stage onto a slice of the job's
 * overall 0–1 progress so the control panel shows steady forward movement.
 *
 * The job passes a setter that closes over its own protected
 * {@see \craft\queue\BaseJob::setProgress()}.
 */
class QueueProgress implements ProgressInterface
{
    private float $stageStart = 0.0;

    /** Where the previous stage ended — the next stage picks up from here. */
    private float $stageEnd = 0.0;
    private int $total = 0;
    private int $done = 0;
    private string $label = '';
    private float $lastPushed = -1.0;

    /**
     * @param Closure $setProgress function(float $progress, ?string $label): void
     * @param array<int, array{0:string,1:float}> $stages Ordered stage labels with the
     *        overall progress fraction each one ends at.
     */
    public function __construct(
        private readonly Closure $setProgress,
        private array $stages = [],
    ) {
    }

    public function start(string $label, int $total = 0): void
    {
        $this->stageStart = $this->stageEnd;
        $this->stageEnd = $this->boundaryFor($label);
        $this->label = $label;
        $this->total = $total;
        $this->done = 0;

        $this->push($this->stageStart, $label, true);
    }

    public function advance(?string $label = null, int $step = 1): void
    {
        $this->done += $step;

        if ($this->total <= 0) {
            return;
        }

        $fraction = min(1.0, $this->done / $this->total);
        $overall = $this->stageStart + ($this->stageEnd - $this->stageStart) * $fraction;

        $this->push($overall, sprintf('%s (%d/%d)', $this->label, min($this->done, $this->total), $this->total));
    }

    public function note(string $message): void
    {
        $this->push($this->stageStart, $message, true);
    }

    public function finish(?string $label = null): void
    {
        $this->push($this->stageEnd, $label ?? $this->label, true);
    }

    /**
     * Where in the overall 0–1 range the named stage ends. Unknown stages simply take
     * the next tenth, so a custom stage still moves the bar forward.
     */
    private function boundaryFor(string $label): float
    {
        foreach ($this->stages as [$name, $boundary]) {
            if ($name === $label) {
                return $boundary;
            }
        }

        return min(1.0, $this->stageEnd + 0.1);
    }

    /**
     * Pushes an update, throttled to whole percentage points — the queue stores progress
     * in the database, so a per-element write would cost more than the work itself.
     * Stage transitions push unconditionally.
     */
    private function push(float $progress, ?string $label, bool $force = false): void
    {
        $progress = round(max(0.0, min(1.0, $progress)), 4);

        if (!$force && abs($progress - $this->lastPushed) < 0.01) {
            return;
        }

        $this->lastPushed = $progress;
        ($this->setProgress)($progress, $label);
    }
}
