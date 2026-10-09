<?php

namespace justinholtweb\transport\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\records\ImportHistory;
use yii\console\ExitCode;

/**
 * Roll back a previous import by its history ID.
 *
 * Usage:
 *   craft transport/rollback 42 --dry-run
 *   craft transport/rollback 42 --interactive=0
 */
class RollbackController extends Controller
{
    /** @var bool List what the rollback would restore and delete, without changing anything. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['dryRun']);
    }

    /**
     * @param int $id The import history ID to roll back.
     */
    public function actionIndex(int $id): int
    {
        $record = ImportHistory::findOne(['id' => $id]);
        if (!$record) {
            $this->stderr("No history record #$id.\n", Console::FG_RED);
            return ExitCode::NOINPUT;
        }

        if ($record->direction !== ImportHistory::DIRECTION_IMPORT || !$record->snapshotId) {
            $this->stderr("History #$id can't be rolled back (no snapshot).\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if ($this->dryRun) {
            return $this->printPlan($record);
        }

        // Before 5.2, --interactive=0 answered this prompt "no" and exited 0 having done nothing.
        if ($this->interactive && !$this->confirm("Roll back import #$id ({$record->packageName})?")) {
            $this->stdout("Nothing rolled back.\n");
            return ExitCode::OK;
        }

        $result = Plugin::getInstance()->snapshots->rollback($record);

        if ($result['errors']) {
            foreach ($result['errors'] as $error) {
                $this->stderr("  - $error\n", Console::FG_RED);
            }
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(
            "Rolled back: {$result['restored']} restored, {$result['deleted']} deleted.\n",
            Console::FG_GREEN
        );
        return ExitCode::OK;
    }

    private function printPlan(ImportHistory $record): int
    {
        $plan = Plugin::getInstance()->snapshots->plan($record);

        if ($plan['errors']) {
            foreach ($plan['errors'] as $error) {
                $this->stderr("  - $error\n", Console::FG_RED);
            }
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Dry run — rolling back #{$record->id} would:\n");
        foreach (['restore' => 'Restore', 'delete' => 'Delete'] as $key => $label) {
            $this->stdout(sprintf("%s (%d)\n", $label, count($plan[$key])), $key === 'delete' ? Console::FG_RED : Console::FG_YELLOW);
            foreach ($plan[$key] as $item) {
                $this->stdout("  · $item\n");
            }
        }
        if ($plan['missing']) {
            $this->stdout(sprintf("Already gone (%d)\n", count($plan['missing'])), Console::FG_GREY);
        }
        if ($record->status === ImportHistory::STATUS_ROLLED_BACK) {
            $this->stdout("Note: this import has already been rolled back once.\n", Console::FG_YELLOW);
        }
        if ($plan['refused']) {
            $this->stderr('Refused — no permission for: ' . implode(', ', $plan['refused']) . "\n", Console::FG_RED);
            return ExitCode::NOPERM;
        }

        $this->stdout("Nothing was changed.\n", Console::FG_GREY);
        return ExitCode::OK;
    }
}
