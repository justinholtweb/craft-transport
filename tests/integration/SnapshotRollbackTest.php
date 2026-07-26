<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use Craft;
use justinholtweb\transport\records\ImportHistory;

/**
 * Snapshot capture and rollback: restoring updated elements and deleting created ones,
 * including the undo snapshot that makes a rollback itself reversible.
 */
final class SnapshotRollbackTest extends TransportTestCase
{
    private function latestImport(): ImportHistory
    {
        return ImportHistory::find()
            ->where(['direction' => 'import'])
            ->orderBy(['id' => SORT_DESC])
            ->one();
    }

    public function testRollbackRestoresAnUpdatedField(): void
    {
        $body = $this->plainTextField();
        $category = $this->makeCategory('Restorable', 'snap-restore', null, [$body->handle => 'original']);
        $path = $this->export(['categories'], 'snap-restore');

        // Diverge locally, then import (which resets to 'original').
        $category->setFieldValue($body->handle, 'local edit');
        Craft::$app->getElements()->saveElement($category);
        $this->plugin()->import->importPackage($path, false);
        self::assertSame('original', $this->findCategory('snap-restore')->getFieldValue($body->handle));

        // Roll the import back — the pre-import 'local edit' state returns.
        $result = $this->plugin()->snapshots->rollback($this->latestImport());

        self::assertSame('completed', $result['status']);
        self::assertSame(1, $result['restored']);
        self::assertSame('local edit', $this->findCategory('snap-restore')->getFieldValue($body->handle));
    }

    public function testRollbackDeletesElementsTheImportCreated(): void
    {
        $this->plainTextField();
        $this->makeCategory('CreatedThenGone', 'snap-delete');
        $path = $this->export(['categories'], 'snap-delete');
        $this->deleteCategories();

        // Import re-creates it.
        $this->plugin()->import->importPackage($path, false);
        self::assertNotNull($this->findCategory('snap-delete'));

        // Rollback deletes what the import created.
        $result = $this->plugin()->snapshots->rollback($this->latestImport());

        self::assertSame('completed', $result['status']);
        self::assertSame(1, $result['deleted']);
        self::assertNull($this->findCategory('snap-delete'));
    }

    public function testRollbackMarksOriginalHistoryRolledBack(): void
    {
        $this->plainTextField();
        $this->makeCategory('Marked', 'snap-marked');
        $path = $this->export(['categories'], 'snap-marked');
        $this->deleteCategories();
        $this->plugin()->import->importPackage($path, false);

        $history = $this->latestImport();
        $this->plugin()->snapshots->rollback($history);

        $refreshed = ImportHistory::findOne(['id' => $history->id]);
        self::assertSame(ImportHistory::STATUS_ROLLED_BACK, $refreshed->status);
    }

    public function testRollbackIsItselfReversible(): void
    {
        $body = $this->plainTextField();
        $category = $this->makeCategory('DoubleUndo', 'snap-redo', null, [$body->handle => 'v-original']);
        $path = $this->export(['categories'], 'snap-redo');

        $category->setFieldValue($body->handle, 'v-local');
        Craft::$app->getElements()->saveElement($category);

        // Import sets 'v-original'; rollback restores 'v-local'.
        $this->plugin()->import->importPackage($path, false);
        $importHistory = $this->latestImport();
        $this->plugin()->snapshots->rollback($importHistory);
        self::assertSame('v-local', $this->findCategory('snap-redo')->getFieldValue($body->handle));

        // The rollback recorded its own snapshot-protected history row; rolling *that*
        // back should return us to the imported 'v-original' state.
        $rollbackHistory = $this->latestImport();
        self::assertNotSame($importHistory->id, $rollbackHistory->id);
        $result = $this->plugin()->snapshots->rollback($rollbackHistory);

        self::assertSame('completed', $result['status']);
        self::assertSame('v-original', $this->findCategory('snap-redo')->getFieldValue($body->handle));
    }

    public function testRollbackWithoutSnapshotFails(): void
    {
        $history = new ImportHistory();
        $history->packageName = 'orphan.zip';
        $history->direction = ImportHistory::DIRECTION_IMPORT;
        $history->status = ImportHistory::STATUS_COMPLETED;
        $history->save(false);

        $result = $this->plugin()->snapshots->rollback($history);

        self::assertSame('failed', $result['status']);
        self::assertNotEmpty($result['errors']);
    }

    public function testCaptureRecordsExistenceState(): void
    {
        $this->plainTextField();
        $existing = $this->makeCategory('Exists', 'snap-cap-exists');

        $entries = $this->plugin()->snapshots->capture([
            ['uid' => $existing->uid, 'type' => \craft\elements\Category::class],
            ['uid' => 'never-existed-uid', 'type' => \craft\elements\Category::class],
        ]);

        self::assertCount(2, $entries);
        self::assertTrue($entries[0]['existed']);
        self::assertArrayHasKey('data', $entries[0]);
        self::assertFalse($entries[1]['existed']);
    }
}
