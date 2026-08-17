<?php

namespace justinholtweb\transport\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\transport\console\ReportPrinter;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\progress\ConsoleProgress;
use justinholtweb\transport\progress\NullProgress;
use yii\console\ExitCode;

/**
 * Import a Transport package.
 *
 * Console imports always run inline — never on the queue — and stream their progress to
 * the terminal, finishing with a detailed report of every element added, updated,
 * skipped and failed.
 *
 * Usage:
 *   craft transport/import path/to/export.zip --dry-run
 *   craft transport/import path/to/export.zip --verbose
 */
class ImportController extends Controller
{
    /** @var bool Simulate the import and report what would change, without saving. */
    public bool $dryRun = false;

    /** @var bool Print a line for every element as it is processed. */
    public bool $verbose = false;

    /** @var bool Suppress progress output; print the final report only. */
    public bool $quiet = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['dryRun', 'verbose', 'quiet']);
    }

    /**
     * @param string $path Path to the package zip.
     */
    public function actionIndex(string $path): int
    {
        if (!is_file($path)) {
            $this->stderr("Package not found: $path\n", Console::FG_RED);
            return ExitCode::NOINPUT;
        }

        $this->stdout(($this->dryRun ? 'Simulating import' : 'Importing') . ": $path\n");

        $progress = $this->quiet ? new NullProgress() : new ConsoleProgress($this, $this->verbose);
        $report = Plugin::getInstance()->import->run($path, $this->dryRun, [], [], $progress);

        (new ReportPrinter($this, $this->verbose ? PHP_INT_MAX : 20))->print($report);

        return $report->errors ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
