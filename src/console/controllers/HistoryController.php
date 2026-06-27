<?php

namespace justinholtweb\transport\console\controllers;

use craft\console\Controller;
use justinholtweb\transport\records\ImportHistory;
use yii\console\ExitCode;

/**
 * List recent Transport import/export history.
 *
 * Usage: craft transport/history
 */
class HistoryController extends Controller
{
    /** @var int Maximum rows to show. */
    public int $limit = 30;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['limit']);
    }

    public function actionIndex(): int
    {
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
}
