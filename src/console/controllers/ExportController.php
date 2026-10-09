<?php

namespace justinholtweb\transport\console\controllers;

use Craft;
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
 *   craft transport/export --ids=12,15 --with-dependencies --package-name=launch
 */
class ExportController extends Controller
{
    /** @var string|null Section handle to export entries from. */
    public ?string $section = null;

    /** @var string|null Site handle to export from (defaults to the primary site). */
    public ?string $site = null;

    /**
     * @var string|null Comma-separated element types (package keys) to export. Defaults to
     *                  entries — or, with --ids, to every type, so the IDs decide.
     */
    public ?string $types = null;

    /** @var string|null Comma-separated element IDs to export (any type), instead of whole sections. */
    public ?string $ids = null;

    /** @var bool Also export everything the selection references (relations, authors, parents, assets). */
    public bool $withDependencies = false;

    /** @var string|null Package filename, without extension. */
    public ?string $packageName = null;

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
            'section', 'site', 'types', 'ids', 'withDependencies', 'packageName', 'all', 'output', 'metadataOnly', 'verbose', 'quiet',
        ]);
    }

    public function actionIndex(): int
    {
        $config = new ExportConfig();
        $config->section = $this->section;
        $config->site = $this->site;
        $config->includeAssetFiles = !$this->metadataOnly;
        $config->includeDependencies = $this->withDependencies;
        $config->packageName = $this->packageName;

        if ($this->ids !== null) {
            $ids = $this->splitList($this->ids);
            foreach ($ids as $id) {
                if (!ctype_digit($id)) {
                    $this->stderr("Not an element ID: $id\n", Console::FG_RED);
                    return ExitCode::USAGE;
                }
            }
            $config->elementIds = array_map('intval', $ids);
            if (!$config->elementIds) {
                $this->stderr("--ids needs at least one element ID.\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
        }

        if ($this->all || ($this->types === null && $config->elementIds)) {
            $config->packageKeys = $this->allPackageKeys();
        } else {
            $config->packageKeys = $this->splitList($this->types ?? 'entries');
            $unknown = array_diff($config->packageKeys, $this->allPackageKeys());
            if ($unknown) {
                $this->stderr('Unknown element type(s): ' . implode(', ', $unknown)
                    . '. Available: ' . implode(', ', $this->allPackageKeys()) . "\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
        }

        if ($this->site !== null && Craft::$app->getSites()->getSiteByHandle($this->site) === null) {
            $this->stderr("No site with the handle \"{$this->site}\".\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        // The same rules the control panel's export form is held to.
        if (!$config->validate()) {
            foreach ($config->getFirstErrors() as $attribute => $error) {
                $this->stderr("--$attribute: $error\n", Console::FG_RED);
            }
            return ExitCode::USAGE;
        }

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
            if (!@copy($path, $this->output)) {
                $this->stderr("Couldn't write the package to {$this->output} (it's still at $path).\n", Console::FG_RED);
                return ExitCode::CANTCREAT;
            }
            $path = $this->output;
        }

        (new ReportPrinter($this, $this->verbose ? PHP_INT_MAX : 20))->print($report);
        $this->stdout("Wrote package: $path\n", Console::FG_GREEN);

        return $report->isSuccessful() ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * @return string[]
     */
    private function splitList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $v) => $v !== ''));
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
