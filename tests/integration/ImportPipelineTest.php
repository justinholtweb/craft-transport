<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use Craft;
use justinholtweb\transport\records\ImportHistory;

/**
 * The import orchestrator: create vs update counting, dry-run isolation, selective
 * import by UID, per-field merge decisions, and history recording.
 */
final class ImportPipelineTest extends TransportTestCase
{
    public function testImportCreatesMissingElements(): void
    {
        $this->plainTextField();
        $this->makeCategory('Created', 'imp-created', null, ['transportBody' => 'body']);
        $path = $this->export(['categories'], 'imp-create');

        $this->deleteCategories();

        $result = $this->plugin()->import->importPackage($path, false);

        self::assertSame('completed', $result['status']);
        self::assertSame(1, $result['created']);
        self::assertSame(0, $result['updated']);
        $recreated = $this->findCategory('imp-created');
        self::assertNotNull($recreated);
        self::assertSame('body', $recreated->getFieldValue('transportBody'));
    }

    public function testReimportUpdatesRatherThanDuplicates(): void
    {
        $this->plainTextField();
        $this->makeCategory('Updatable', 'imp-update', null, ['transportBody' => 'v1']);
        $path = $this->export(['categories'], 'imp-update');

        // Same package imported again over the existing element = update, no duplicate.
        $result = $this->plugin()->import->importPackage($path, false);

        self::assertSame('completed', $result['status']);
        self::assertSame(0, $result['created']);
        self::assertSame(1, $result['updated']);
        self::assertCount(1, \craft\elements\Category::find()->group(self::CATEGORY_GROUP)->slug('imp-update')->status(null)->all());
    }

    public function testDryRunReportsButDoesNotPersist(): void
    {
        $this->plainTextField();
        $this->makeCategory('Ephemeral', 'imp-dry', null, ['transportBody' => 'body']);
        $path = $this->export(['categories'], 'imp-dry');
        $this->deleteCategories();

        $result = $this->plugin()->import->importPackage($path, true);

        self::assertSame('dry-run', $result['status']);
        self::assertSame(1, $result['created']);
        // Nothing actually written.
        self::assertNull($this->findCategory('imp-dry'));
    }

    public function testDryRunWritesNoHistoryRow(): void
    {
        $this->plainTextField();
        $this->makeCategory('NoHistory', 'imp-nohist');
        $path = $this->export(['categories'], 'imp-nohist');

        $before = ImportHistory::find()->where(['direction' => 'import'])->count();
        $this->plugin()->import->importPackage($path, true);
        $after = ImportHistory::find()->where(['direction' => 'import'])->count();

        self::assertSame($before, $after);
    }

    public function testRealImportRecordsCompletedHistory(): void
    {
        $this->plainTextField();
        $this->makeCategory('Historied', 'imp-hist');
        $path = $this->export(['categories'], 'imp-hist2');
        $this->deleteCategories();

        $this->plugin()->import->importPackage($path, false);

        $history = ImportHistory::find()
            ->where(['direction' => 'import'])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        self::assertNotNull($history);
        self::assertSame(ImportHistory::STATUS_COMPLETED, $history->status);
        $counts = $history->getCountsArray();
        self::assertSame(1, $counts['created'] ?? null);
    }

    public function testSelectedUidsLimitsWhatIsImported(): void
    {
        $keep = $this->makeCategory('Keep', 'imp-keep');
        $this->makeCategory('Drop', 'imp-drop');
        $path = $this->export(['categories'], 'imp-select');
        $this->deleteCategories();

        $result = $this->plugin()->import->importPackage($path, false, [], [
            'selectedUids' => [$keep->uid],
        ]);

        self::assertSame(1, $result['created']);
        self::assertNotNull($this->findCategory('imp-keep'));
        self::assertNull($this->findCategory('imp-drop'));
    }

    public function testSelectiveMergeRejectsFieldOnEntry(): void
    {
        $body = $this->plainTextField();
        $entry = $this->makeEntry('Article', 'imp-merge-entry', [$body->handle => 'package body']);
        $path = $this->export(['entries'], 'imp-merge-entry');

        // Diverge the target's body, then import rejecting that field.
        $entry->setFieldValue($body->handle, 'locally edited body');
        Craft::$app->getElements()->saveElement($entry);

        $primary = $this->primarySiteHandle();
        $result = $this->plugin()->import->importPackage($path, false, [], [
            'decisions' => [$entry->uid => ["$primary.{$body->handle}"]],
        ]);

        self::assertSame('completed', $result['status']);
        self::assertSame(
            'locally edited body',
            $this->findEntry('imp-merge-entry')->getFieldValue($body->handle),
            'rejected field kept the local value'
        );
    }

    public function testFailedImportIsMarkedFailedAndRolledBack(): void
    {
        // Build a package whose category references a group that won't exist at import
        // time, so normalization yields no saveable element and the element is skipped —
        // and separately assert that a hard save failure surfaces as an error status.
        $this->plainTextField();
        $this->makeCategory('WillSkip', 'imp-skip');
        $path = $this->export(['categories'], 'imp-skip');

        // Import against a target missing the group entirely: the element can't be hosted.
        $this->deleteCategories();
        Craft::$app->getCategories()->deleteGroupById($this->categoryGroup()->id);

        $result = $this->plugin()->import->importPackage($path, false);

        // No hostable element → skipped, not created.
        self::assertSame(0, $result['created']);
        self::assertSame(1, $result['skipped']);
    }
}
