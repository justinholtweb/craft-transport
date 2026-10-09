<?php

namespace justinholtweb\transport\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use justinholtweb\transport\console\ReportPrinter;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\records\ImportHistory;
use yii\console\ExitCode;

/**
 * Transport's import/export history: the list, one run's report, and an export's package.
 *
 * Usage:
 *   craft transport/history
 *   craft transport/history/view 42 --verbose
 *   craft transport/history/download 41 --output=launch.zip
 */
class HistoryController extends Controller
{
    /** @var int Maximum rows to show. */
    public int $limit = 30;

    /** @var bool List every element in a report, not just the first 20 per outcome. */
    public bool $verbose = false;

    /** @var string|null Where `download` writes the package (defaults to its own name in the working directory). */
    public ?string $output = null;

    public function options($actionID): array
    {
        $options = match ($actionID) {
            'view' => ['verbose'],
            'download' => ['output'],
            default => ['limit'],
        };

        return array_merge(parent::options($actionID), $options);
    }

    public function actionIndex(): int
    {
        /** @var ImportHistory[] $rows */
        $rows = ImportHistory::find()
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($this->limit)
            ->all();

        if (!$rows) {
            $this->stdout("No history yet.\n");
            return ExitCode::OK;
        }

        $this->stdout(sprintf("%-5s %-9s %-12s %-24s %s\n", 'ID', 'DIR', 'STATUS', 'DATE', 'PACKAGE'));
        foreach ($rows as $row) {
            $this->stdout(sprintf(
                "%-5d %-9s %-12s %-24s %s\n",
                $row->id,
                $row->direction,
                $row->status,
                $row->dateCreated,
                $row->packageName
            ));
        }

        return ExitCode::OK;
    }

    /**
     * Prints one run's report — the History detail screen.
     *
     * @param int $id The history ID.
     */
    public function actionView(int $id): int
    {
        $record = ImportHistory::findOne(['id' => $id]);
        if (!$record) {
            $this->stderr("No history record #$id.\n", Console::FG_RED);
            return ExitCode::NOINPUT;
        }

        $this->stdout(sprintf(
            "#%d %s · %s · %s · %s\n",
            $record->id,
            $record->direction,
            $record->status,
            $record->dateCreated,
            $record->packageName
        ));

        if ($record->snapshotId && $record->direction === ImportHistory::DIRECTION_IMPORT) {
            $this->stdout("Can be rolled back: craft transport/rollback {$record->id}\n", Console::FG_GREY);
        }

        $report = $record->getRunReport();
        if ($report !== null) {
            (new ReportPrinter($this, $this->verbose ? PHP_INT_MAX : 20))->print($report);
        } else {
            // Rows from before reports were stored (and rollbacks) only have counts.
            foreach ($record->getCountsArray() as $key => $count) {
                $this->stdout(sprintf("  %-16s %s\n", $key, is_scalar($count) ? $count : Json::encode($count)));
            }
            $errors = $record->errorLog ? (Json::decodeIfJson($record->errorLog) ?: []) : [];
            foreach (is_array($errors) ? $errors : [$record->errorLog] as $error) {
                $this->stderr('  - ' . (is_scalar($error) ? $error : Json::encode($error)) . "\n", Console::FG_RED);
            }
        }

        return $record->status === ImportHistory::STATUS_FAILED ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Copies the package a past export wrote out of Transport's temp directory — the
     * History screen's Download button.
     *
     * @param int $id The export's history ID.
     */
    public function actionDownload(int $id): int
    {
        $record = ImportHistory::findOne(['id' => $id]);
        if (!$record || $record->direction !== ImportHistory::DIRECTION_EXPORT || !$record->packageName) {
            $this->stderr("History #$id isn't an export with a package.\n", Console::FG_RED);
            return ExitCode::NOINPUT;
        }

        // basename() so a crafted record can't point outside the temp directory.
        $source = Plugin::getInstance()->getSettings()->getResolvedTempPath()
            . DIRECTORY_SEPARATOR
            . basename($record->packageName);

        if (!is_file($source)) {
            $this->stderr("That package is no longer on disk.\n", Console::FG_RED);
            return ExitCode::NOINPUT;
        }

        $target = $this->output ?? basename($source);
        if (!@copy($source, $target)) {
            $this->stderr("Couldn't write $target.\n", Console::FG_RED);
            return ExitCode::CANTCREAT;
        }

        $this->stdout("Wrote package: $target\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}
