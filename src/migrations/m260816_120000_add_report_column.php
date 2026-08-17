<?php

namespace justinholtweb\transport\migrations;

use craft\db\Migration;
use justinholtweb\transport\records\ImportHistory;

/**
 * Adds the detailed run report column to the history table.
 */
class m260816_120000_add_report_column extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(ImportHistory::TABLE, 'report')) {
            $this->addColumn(ImportHistory::TABLE, 'report', $this->longText()->after('elementCounts'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(ImportHistory::TABLE, 'report')) {
            $this->dropColumn(ImportHistory::TABLE, 'report');
        }

        return true;
    }
}
