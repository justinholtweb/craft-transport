<?php

namespace justinholtweb\transport\progress;

/**
 * Discards progress updates — the default when nobody is watching.
 */
class NullProgress implements ProgressInterface
{
    public function start(string $label, int $total = 0): void
    {
    }

    public function advance(?string $label = null, int $step = 1): void
    {
    }

    public function note(string $message): void
    {
    }

    public function finish(?string $label = null): void
    {
    }
}
