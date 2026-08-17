<?php

namespace justinholtweb\transport\progress;

/**
 * Receives progress updates from a long-running export or import.
 *
 * The services report progress through this interface so the same code path can drive
 * a queue job's progress bar ({@see QueueProgress}), a live console display
 * ({@see ConsoleProgress}), or nothing at all ({@see NullProgress}).
 */
interface ProgressInterface
{
    /**
     * Begins a stage of work.
     *
     * @param string $label What the stage is doing.
     * @param int $total Number of steps in the stage, or 0 when it isn't countable.
     */
    public function start(string $label, int $total = 0): void;

    /**
     * Advances the current stage by one or more steps.
     *
     * @param string|null $label Optional label for the item just processed.
     */
    public function advance(?string $label = null, int $step = 1): void;

    /**
     * Reports a one-off message that isn't a step (a warning, a count, a decision).
     */
    public function note(string $message): void;

    /**
     * Ends the current stage.
     */
    public function finish(?string $label = null): void;
}
