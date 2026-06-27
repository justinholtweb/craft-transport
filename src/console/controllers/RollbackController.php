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
 * Usage: craft transport/rollback 42
 */
class RollbackController extends Controller
{
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

        if (!$this->confirm("Roll back import #$id ({$record->packageName})?")) {
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
}
