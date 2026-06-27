<?php

namespace justinholtweb\transport\migrations;

use craft\db\Migration;
use justinholtweb\transport\records\ImportHistory;
use justinholtweb\transport\records\ElementSnapshot;

/**
 * Install migration — creates Transport's history and snapshot tables.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(ImportHistory::TABLE)) {
            $this->createTable(ImportHistory::TABLE, [
                'id' => $this->primaryKey(),
                'packageName' => $this->string()->notNull(),
                'direction' => $this->enum('direction', ['export', 'import'])->notNull(),
                'status' => $this->enum('status', ['pending', 'running', 'completed', 'failed', 'rolled_back'])
                    ->notNull()
                    ->defaultValue('pending'),
                'elementCounts' => $this->json(),
                'errorLog' => $this->text(),
                'snapshotId' => $this->integer(),
                'userId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, ImportHistory::TABLE, ['direction']);
            $this->createIndex(null, ImportHistory::TABLE, ['status']);
        }

        if (!$this->db->tableExists(ElementSnapshot::TABLE)) {
            $this->createTable(ElementSnapshot::TABLE, [
                'id' => $this->primaryKey(),
                'historyId' => $this->integer()->notNull(),
                'elementData' => $this->longText(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, ElementSnapshot::TABLE, ['historyId']);
        }

        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(ElementSnapshot::TABLE);
        $this->dropTableIfExists(ImportHistory::TABLE);

        return true;
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(
            null,
            ImportHistory::TABLE,
            ['userId'],
            '{{%users}}',
            ['id'],
            'SET NULL',
            null
        );

        $this->addForeignKey(
            null,
            ElementSnapshot::TABLE,
            ['historyId'],
            ImportHistory::TABLE,
            ['id'],
            'CASCADE',
            null
        );
    }
}
