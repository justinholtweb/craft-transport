<?php

namespace justinholtweb\transport\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\transport\console\ReportPrinter;
use justinholtweb\transport\models\ExportConfig;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\progress\ConsoleProgress;
use justinholtweb\transport\progress\NullProgress;
use yii\console\ExitCode;

/**
 * Export content to a Transport package.
 *
 * Console exports always run inline — never on the queue — and stream their progress to
 * the terminal, finishing with a detailed report of what was exported and skipped.
 *
 * Usage:
 *   craft transport/export --section=blog --site=default --output=blog.zip
 *   craft transport/export --types=entries,categories,assets --all --verbose
 */
class ExportController extends Controller
{
    /** @var string|null Section handle to export entries from. */
    public ?string $section = null;

    /** @var string|null Site handle to export from (defaults to the primary site). */
    public ?string $site = null;

    /** @var string Comma-separated element types (package keys) to export. */
    public string $types = 'entries';

    /** @var bool Export every supported element type. */
    public bool $all = false;

    /** @var string|null Destination path for the package zip. */
    public ?string $output = null;

    /** @var bool Exclude asset files (metadata only). */
    public bool $metadataOnly = false;

    /** @var bool Print a line for every element as it is processed. */
    public bool $verbose = false;

    /** @var bool Suppress progress output; print the final report only. */
    public bool $quiet = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'section', 'site', 'types', 'all', 'output', 'metadataOnly', 'verbose', 'quiet',
        ]);
    }

    public function actionIndex(): int
    {
        $config = new ExportConfig();
        $config->section = $this->section;
        $config->site = $this->site;
        $config->includeAssetFiles = !$this->metadataOnly;

        $config->packageKeys = $this->all
            ? $this->allPackageKeys()
            : array_values(array_filter(array_map('trim', explode(',', $this->types))));

        $this->stdout('Exporting: ' . implode(', ', $config->packageKeys) . "\n");

        $progress = $this->quiet ? new NullProgress() : new ConsoleProgress($this, $this->verbose);
        $report = Plugin::getInstance()->export->run($config, $progress);

        $path = $report->packagePath ?? '';

        if ($path === '') {
            $this->stderr("No package was written.\n", Console::FG_RED);
            (new ReportPrinter($this, $this->verbose ? PHP_INT_MAX : 20))->print($report);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->output) {
            copy($path, $this->output);
            $path = $this->output;
        }

        (new ReportPrinter($this, $this->verbose ? PHP_INT_MAX : 20))->print($report);
        $this->stdout("Wrote package: $path\n", Console::FG_GREEN);

        return $report->isSuccessful() ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * @return string[]
     */
    private function allPackageKeys(): array
    {
        $keys = [];
        foreach (Plugin::getInstance()->elementRegistry->all() as $handler) {
            $keys[] = $handler->packageKey();
        }
        return $keys;
    }
}
