<?php

namespace justinholtweb\transport\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\transport\models\ExportConfig;
use justinholtweb\transport\Plugin;
use yii\console\ExitCode;

/**
 * Export content to a Transport package.
 *
 * Usage:
 *   craft transport/export --section=blog --site=default --output=blog.zip
 *   craft transport/export --types=entries,categories,assets --all
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

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'section', 'site', 'types', 'all', 'output', 'metadataOnly',
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

        $path = Plugin::getInstance()->export->export($config);

        if ($this->output) {
            copy($path, $this->output);
            $path = $this->output;
        }

        $this->stdout("Wrote package: $path\n", Console::FG_GREEN);
        return ExitCode::OK;
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
