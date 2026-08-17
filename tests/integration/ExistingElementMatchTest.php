<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use justinholtweb\transport\models\TransportReport;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\records\ImportHistory;

/**
 * Importing content that already exists here under a different UID.
 *
 * Content routinely exists in two environments with unrelated UIDs — a single created
 * independently in each, content seeded before Transport was installed. Such an element
 * has to be updated, not added a second time: Craft either duplicates it or, when its
 * URI format can't be made unique (as for a single), refuses to save it at all.
 */
final class ExistingElementMatchTest extends TransportTestCase
{
    /**
     * Runs the callback with natural-key matching turned off — the strict UID-only
     * identity Transport had before.
     */
    private function withoutMatching(callable $callback): mixed
    {
        $settings = Plugin::getInstance()->getSettings();
        $settings->matchExistingElements = false;

        try {
            return $callback();
        } finally {
            $settings->matchExistingElements = true;
        }
    }

    private function singleEntry(): Entry
    {
        return Entry::find()
            ->section($this->singleSection()->handle)
            ->status(null)
            ->one();
    }

    // ------------------------------------------------------------------
    // The reported failure
    // ------------------------------------------------------------------

    public function testSingleThatAlreadyExistsIsUpdatedNotDuplicated(): void
    {
        $single = $this->singleEntry();
        $single->title = 'FabFest Homepage';
        $this->saveElement($single);

        $path = $this->export(['entries'], 'match-single');

        // The same page, created independently here: same section, different UID.
        $this->reassignUid($single);

        $report = $this->plugin()->import->run($path);

        self::assertSame(TransportReport::STATUS_COMPLETED, $report->status);
        self::assertSame([], $report->errors);
        self::assertSame(1, $report->countOf(TransportReport::ACTION_UPDATED));
        self::assertSame(0, $report->countOf(TransportReport::ACTION_CREATED));

        self::assertCount(1, Entry::find()
            ->section($this->singleSection()->handle)
            ->status(null)
            ->all(), 'the single still has exactly one entry');
    }

    public function testWithoutMatchingTheSameImportFailsOnTheUri(): void
    {
        $single = $this->singleEntry();
        $single->title = 'FabFest Homepage';
        $this->saveElement($single);

        $path = $this->export(['entries'], 'match-single-off');
        $this->reassignUid($single);

        $report = $this->withoutMatching(fn() => $this->plugin()->import->run($path));

        // This is the failure the matching exists to prevent.
        self::assertSame(TransportReport::STATUS_FAILED, $report->status);
        self::assertStringContainsString('unique URI', implode(' ', $report->errors));
    }

    // ------------------------------------------------------------------
    // Other element types
    // ------------------------------------------------------------------

    public function testEntryIsMatchedBySlugWithinItsSection(): void
    {
        $entry = $this->makeEntry('Channel Post', 'match-channel-post');
        $path = $this->export(['entries'], 'match-channel');
        $this->reassignUid($entry);

        $report = $this->plugin()->import->run($path);

        self::assertSame(1, $report->countOf(TransportReport::ACTION_UPDATED));
        self::assertCount(1, Entry::find()->section(self::SECTION)->slug('match-channel-post')->status(null)->all());
    }

    public function testCategoryIsMatchedBySlugWithinItsGroup(): void
    {
        $category = $this->makeCategory('Matched Category', 'match-category');
        $path = $this->export(['categories'], 'match-cat');
        $this->reassignUid($category);

        $report = $this->plugin()->import->run($path);

        self::assertSame(1, $report->countOf(TransportReport::ACTION_UPDATED));
        self::assertCount(1, Category::find()->group(self::CATEGORY_GROUP)->slug('match-category')->status(null)->all());
    }

    public function testMatchedElementKeepsItsOwnUid(): void
    {
        $category = $this->makeCategory('Keeps Uid', 'match-keeps-uid');
        $path = $this->export(['categories'], 'match-keeps-uid');
        $localUid = $this->reassignUid($category);

        $this->plugin()->import->run($path);

        self::assertSame($localUid, $this->findCategory('match-keeps-uid')->uid,
            'adopting content must not rewrite the local element’s identity');
    }

    public function testIncomingContentIsAppliedToTheMatchedElement(): void
    {
        $this->plainTextField();
        $category = $this->makeCategory('Before', 'match-content', null, ['transportBody' => 'exported body']);
        $path = $this->export(['categories'], 'match-content');

        $this->reassignUid($category);
        $category->setFieldValue('transportBody', 'local body');
        $category->title = 'Local Title';
        $this->saveElement($category);

        $this->plugin()->import->run($path);

        $updated = $this->findCategory('match-content');
        self::assertSame('exported body', $updated->getFieldValue('transportBody'));
        self::assertSame('Before', $updated->title);
    }

    // ------------------------------------------------------------------
    // Reporting, preview, rollback
    // ------------------------------------------------------------------

    public function testReportSaysWhatItAdopted(): void
    {
        $category = $this->makeCategory('Adopted', 'match-reported');
        $path = $this->export(['categories'], 'match-report');
        $this->reassignUid($category);

        $report = $this->plugin()->import->run($path);
        $items = $report->itemsFor(TransportReport::ACTION_UPDATED);

        self::assertCount(1, $items);
        self::assertStringContainsString('different UID', $items[0]['detail']);
    }

    public function testPreviewShowsAnUpdateRatherThanAnAdd(): void
    {
        $category = $this->makeCategory('Previewed', 'match-preview');
        $path = $this->export(['categories'], 'match-preview');
        $this->reassignUid($category);

        $diffs = $this->plugin()->differ->diffPackage($this->plugin()->packages->open($path));

        self::assertCount(1, $diffs);
        self::assertTrue($diffs[0]->exists);
        self::assertTrue($diffs[0]->matchedByNaturalKey);
        self::assertNotSame('add', $diffs[0]->action, 'the preview must not promise an add the import won’t perform');
    }

    public function testRollbackRestoresAnAdoptedElementRatherThanDeletingIt(): void
    {
        $this->plainTextField();
        $category = $this->makeCategory('Rollbackable', 'match-rollback', null, ['transportBody' => 'exported']);
        $path = $this->export(['categories'], 'match-rollback');

        $this->reassignUid($category);
        $category->setFieldValue('transportBody', 'local');
        $this->saveElement($category);

        $report = $this->plugin()->import->run($path);
        self::assertSame('exported', $this->findCategory('match-rollback')->getFieldValue('transportBody'));

        $history = ImportHistory::findOne(['id' => $report->historyId]);
        $result = $this->plugin()->snapshots->rollback($history);

        self::assertSame([], $result['errors']);
        self::assertSame(0, $result['deleted'], 'an adopted element was updated, so rollback must not delete it');

        $restored = $this->findCategory('match-rollback');
        self::assertNotNull($restored, 'the pre-existing element must survive the rollback');
        self::assertSame('local', $restored->getFieldValue('transportBody'));
    }

    // ------------------------------------------------------------------
    // Guard rails
    // ------------------------------------------------------------------

    public function testMatchingIsScopedToTheSameSectionOrGroup(): void
    {
        // Same slug, different group: not the same content, so nothing may be adopted.
        $category = $this->makeCategory('Shared Slug', 'match-shared-slug');
        $path = $this->export(['categories'], 'match-scope');
        $this->reassignUid($category);

        $entry = $this->makeEntry('Shared Slug', 'match-shared-slug');
        $entryUid = $entry->uid;

        $this->plugin()->import->run($path);

        self::assertSame($entryUid, $this->findEntry('match-shared-slug')->uid,
            'a category must never adopt an entry that happens to share its slug');
    }

    public function testReferencesStillResolveByUidAlone(): void
    {
        // A relation pointing at a UID that isn't here must stay unresolved rather than
        // fuzzily latching onto similar content.
        $this->makeCategory('Referenced', 'match-reference');

        self::assertNull(
            \justinholtweb\transport\helpers\IdentityHelper::resolveId(
                '00000000-0000-0000-0000-000000000000',
                Category::class
            )
        );
    }

    public function testMatchingCanBeTurnedOff(): void
    {
        $category = $this->makeCategory('Strict', 'match-strict');
        $path = $this->export(['categories'], 'match-strict');
        $this->reassignUid($category);

        $report = $this->withoutMatching(fn() => $this->plugin()->import->run($path));

        self::assertSame(1, $report->countOf(TransportReport::ACTION_CREATED),
            'with matching off, identity is strictly by UID');
        self::assertCount(2, Category::find()->group(self::CATEGORY_GROUP)->slug(['match-strict', 'match-strict-2'])->status(null)->all());
    }
}
