<?php

namespace justinholtweb\transport\progress;

use craft\console\Controller;
use craft\helpers\Console;

/**
 * Renders live progress in the terminal: a bar with an ETA for countable stages, and
 * plain status lines for everything else.
 *
 * Console runs are always synchronous — this is what you watch instead of a queue job's
 * progress bar in the control panel.
 */
class ConsoleProgress implements ProgressInterface
{
    private int $total = 0;
    private int $done = 0;
    private string $label = '';
    private bool $barActive = false;

    /**
     * @param Controller $controller The command doing the work, used for output.
     * @param bool $verbose Whether to echo a line per processed element.
     */
    public function __construct(
        private readonly Controller $controller,
        private readonly bool $verbose = false,
    ) {
    }

    public function start(string $label, int $total = 0): void
    {
        $this->endBar();

        $this->label = $label;
        $this->total = $total;
        $this->done = 0;

        if ($total > 0) {
            Console::startProgress(0, $total, $this->prefix($label), 40);
            $this->barActive = true;
            return;
        }

        $this->controller->stdout("$label\n", Console::FG_CYAN);
    }

    public function advance(?string $label = null, int $step = 1): void
    {
        $this->done += $step;

        if ($this->barActive) {
            Console::updateProgress(min($this->done, $this->total), $this->total, $this->prefix($this->label));
        }

        if ($this->verbose && $label !== null) {
            $this->line("  · $label");
        }
    }

    public function note(string $message): void
    {
        $this->line("  $message");
    }

    public function finish(?string $label = null): void
    {
        $this->endBar();

        if ($label !== null) {
            $this->controller->stdout("$label\n", Console::FG_GREY);
        }
    }

    /**
     * Prints a line without corrupting an in-flight progress bar: the bar is torn down,
     * the line written, then the bar restored at its current position.
     */
    private function line(string $text): void
    {
        if (!$this->barActive) {
            $this->controller->stdout("$text\n");
            return;
        }

        // Clear the bar (without re-printing its prefix), write the line, then redraw.
        Console::endProgress(true, false);
        $this->controller->stdout("$text\n");
        Console::startProgress($this->done, $this->total, $this->prefix($this->label), 40);
    }

    private function endBar(): void
    {
        if ($this->barActive) {
            Console::endProgress();
            $this->barActive = false;
        }
    }

    private function prefix(string $label): string
    {
        return $label . ' ';
    }
}
