<?php

namespace justinholtweb\transport\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\transport\Plugin;
use yii\console\ExitCode;

/**
 * Import a Transport package.
 *
 * Usage:
 *   craft transport/import path/to/export.zip --dry-run
 *   craft transport/import path/to/export.zip
 */
class ImportController extends Controller
{
    /** @var bool Simulate the import and report what would change, without saving. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['dryRun']);
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

        $result = Plugin::getInstance()->import->importPackage($path, $this->dryRun);

        $this->stdout(sprintf(
            "Status: %s — created %d, updated %d, skipped %d\n",
            $result['status'],
            $result['created'],
            $result['updated'],
            $result['skipped']
        ), $result['errors'] ? Console::FG_YELLOW : Console::FG_GREEN);

        foreach ($result['errors'] as $error) {
            $this->stderr("  - $error\n", Console::FG_RED);
        }

        return $result['errors'] ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
