<?php

namespace justinholtweb\transport\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\transport\console\ReportPrinter;
use justinholtweb\transport\models\DiffResult;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\progress\ConsoleProgress;
use justinholtweb\transport\progress\NullProgress;
use yii\console\ExitCode;

/**
 * Import a Transport package, or preview what importing it would change.
 *
 * Console imports always run inline — never on the queue — and stream their progress to
 * the terminal, finishing with a detailed report of every element added, updated,
 * skipped and failed. Like the control panel wizard, a real import is refused while
 * pre-flight validation finds blocking problems (a dry run still goes ahead).
 *
 * Usage:
 *   craft transport/import/diff path/to/export.zip --verbose
 *   craft transport/import path/to/export.zip --dry-run
 *   craft transport/import path/to/export.zip --only=<uid>,<uid> --keep-local=<uid>:default.title
 */
class ImportController extends Controller
{
    /** @var bool Simulate the import and report what would change, without saving. */
    public bool $dryRun = false;

    /** @var string|null Comma-separated element UIDs to import (the wizard's selection); others are skipped. */
    public ?string $only = null;

    /**
     * @var string|null Comma-separated `<uid>:<site>.<field>` paths whose current value to keep
     *                  (the wizard's unticked changes). Paths are listed by `transport/import/diff --verbose`.
     */
    public ?string $keepLocal = null;

    /** @var bool Print a line for every element as it is processed. */
    public bool $verbose = false;

    /** @var bool Suppress progress output; print the final report only. */
    public bool $quiet = false;

    public function options($actionID): array
    {
        $options = ['verbose'];
        if ($actionID === 'index') {
            $options = array_merge($options, ['dryRun', 'only', 'keepLocal', 'quiet']);
        }

        return array_merge(parent::options($actionID), $options);
    }

    /**
     * Imports a package.
     *
     * @param string $path Path to the package zip.
     */
    public function actionIndex(string $path): int
    {
        if (!is_file($path)) {
            $this->stderr("Package not found: $path\n", Console::FG_RED);
            return ExitCode::NOINPUT;
        }

        $plugin = Plugin::getInstance();

        // The same pre-flight check that blocks the control panel wizard's Run step.
        if (!$this->dryRun) {
            $validation = $plugin->validation->validate($plugin->packages->open($path));
            if ($validation['errors']) {
                $this->stderr("Import blocked:\n", Console::FG_RED);
                foreach ($validation['errors'] as $error) {
                    $this->stderr("  - $error\n", Console::FG_RED);
                }
                $this->stderr("Run with --dry-run to see what would happen anyway.\n", Console::FG_GREY);
                return ExitCode::DATAERR;
            }
        }

        $options = [];
        if ($this->only !== null) {
            $options['selectedUids'] = $this->splitList($this->only);
            if (!$options['selectedUids']) {
                $this->stderr("--only needs at least one element UID.\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
        }

        if ($this->keepLocal !== null) {
            $decisions = [];
            foreach ($this->splitList($this->keepLocal) as $item) {
                [$uid, $fieldPath] = array_pad(explode(':', $item, 2), 2, '');
                if ($uid === '' || !str_contains($fieldPath, '.')) {
                    $this->stderr("--keep-local expects <uid>:<site>.<field>, got \"$item\".\n", Console::FG_RED);
                    return ExitCode::USAGE;
                }
                $decisions[$uid][] = $fieldPath;
            }
            $options['decisions'] = $decisions;
        }

        $this->stdout(($this->dryRun ? 'Simulating import' : 'Importing') . ": $path\n");

        $progress = $this->quiet ? new NullProgress() : new ConsoleProgress($this, $this->verbose);
        $report = $plugin->import->run($path, $this->dryRun, [], $options, $progress);

        (new ReportPrinter($this, $this->verbose ? PHP_INT_MAX : 20))->print($report);

        return $report->errors || !$report->isSuccessful() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Shows what importing a package would change — the wizard's Configure and Preview
     * steps: pre-flight validation, then every element as add / update / unchanged, and
     * with --verbose each changed field and the path to pass to --keep-local.
     *
     * Exits non-zero when validation finds blocking problems.
     *
     * @param string $path Path to the package zip.
     */
    public function actionDiff(string $path): int
    {
        if (!is_file($path)) {
            $this->stderr("Package not found: $path\n", Console::FG_RED);
            return ExitCode::NOINPUT;
        }

        $plugin = Plugin::getInstance();
        $package = $plugin->packages->open($path);

        $problems = $plugin->packages->validate($package);
        if ($problems) {
            foreach ($problems as $problem) {
                $this->stderr("  - $problem\n", Console::FG_RED);
            }
            return ExitCode::DATAERR;
        }

        $validation = $plugin->validation->validate($package);
        $diffs = $plugin->differ->diffPackage($package);

        $summary = [DiffResult::ACTION_ADD => 0, DiffResult::ACTION_UPDATE => 0, DiffResult::ACTION_UNCHANGED => 0];
        $colors = [
            DiffResult::ACTION_ADD => Console::FG_GREEN,
            DiffResult::ACTION_UPDATE => Console::FG_YELLOW,
            DiffResult::ACTION_UNCHANGED => Console::FG_GREY,
        ];

        foreach ($diffs as $diff) {
            $summary[$diff->action]++;

            if ($diff->action === DiffResult::ACTION_UNCHANGED && !$this->verbose) {
                continue;
            }

            $this->stdout(sprintf("%-9s %-12s %s  %s", $diff->action, $diff->key, $diff->uid, $diff->title), $colors[$diff->action]);
            if ($diff->action === DiffResult::ACTION_UPDATE) {
                $this->stdout(sprintf(' — %d change(s)%s', $diff->changeCount(), $diff->matchedByNaturalKey ? ', matched by natural key' : ''));
            }
            $this->stdout("\n");

            if ($this->verbose && $diff->action === DiffResult::ACTION_UPDATE) {
                foreach ($diff->changes() as $change) {
                    $this->stdout(sprintf(
                        "    %s: %s → %s\n",
                        $change->path(),
                        $this->clip($change->oldDisplay),
                        $this->clip($change->newDisplay)
                    ), Console::FG_GREY);
                }
            }
        }

        $this->stdout(sprintf(
            "\n%d to add, %d to update, %d unchanged.\n",
            $summary[DiffResult::ACTION_ADD],
            $summary[DiffResult::ACTION_UPDATE],
            $summary[DiffResult::ACTION_UNCHANGED]
        ));

        foreach ($validation['warnings'] as $warning) {
            $this->stdout("Warning: $warning\n", Console::FG_YELLOW);
        }

        if ($validation['errors']) {
            foreach ($validation['errors'] as $error) {
                $this->stderr("Blocking: $error\n", Console::FG_RED);
            }
            return ExitCode::DATAERR;
        }

        return ExitCode::OK;
    }

    /**
     * @return string[]
     */
    private function splitList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $v) => $v !== ''));
    }

    private function clip(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return mb_strlen($value) > 60 ? mb_substr($value, 0, 57) . '…' : ($value === '' ? '∅' : $value);
    }
}
