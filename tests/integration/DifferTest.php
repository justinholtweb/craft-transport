<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use Craft;
use justinholtweb\transport\models\DiffEntry;
use justinholtweb\transport\models\DiffResult;

/**
 * Field-level diffing of a package element against the target environment.
 */
final class DifferTest extends TransportTestCase
{
    private function serialize(\craft\base\ElementInterface $element): array
    {
        return $this->plugin()->serializer->serializeElement($element);
    }

    public function testDiffAgainstMissingElementIsAnAdd(): void
    {
        $this->plainTextField();
        $category = $this->makeCategory('Fresh', 'diff-fresh', null, ['transportBody' => 'body']);
        $data = $this->serialize($category);

        // Delete it so the diff has no counterpart in the target.
        Craft::$app->getElements()->deleteElement($category, true);

        $result = $this->plugin()->differ->diffElement($data);

        self::assertFalse($result->exists);
        self::assertSame(DiffResult::ACTION_ADD, $result->action);
        self::assertSame('Fresh', $result->title);
        // Every non-null incoming value shows up as an "added" entry.
        $statuses = array_map(static fn(DiffEntry $e) => $e->status, $result->entries);
        self::assertContains(DiffEntry::STATUS_ADDED, $statuses);
    }

    public function testIdenticalElementDiffsAsUnchanged(): void
    {
        $this->plainTextField();
        $category = $this->makeCategory('Same', 'diff-same', null, ['transportBody' => 'identical']);
        $data = $this->serialize($category);

        $result = $this->plugin()->differ->diffElement($data);

        self::assertTrue($result->exists);
        self::assertSame(DiffResult::ACTION_UNCHANGED, $result->action);
        self::assertSame(0, $result->changeCount());
    }

    public function testChangedFieldIsDetected(): void
    {
        $this->plainTextField();
        $category = $this->makeCategory('Before', 'diff-change', null, ['transportBody' => 'old body']);
        $data = $this->serialize($category);

        // Diverge the incoming payload's body and title.
        $primary = $this->primarySiteHandle();
        $data['sites'][$primary]['fields']['transportBody'] = 'new body';
        $data['sites'][$primary]['title'] = 'After';

        $result = $this->plugin()->differ->diffElement($data);

        self::assertSame(DiffResult::ACTION_UPDATE, $result->action);
        self::assertSame(2, $result->changeCount());

        $changedFields = array_map(static fn(DiffEntry $e) => $e->field, $result->changes());
        self::assertContains('transportBody', $changedFields);
        self::assertContains('title', $changedFields);

        foreach ($result->changes() as $entry) {
            self::assertSame(DiffEntry::STATUS_CHANGED, $entry->status);
        }
    }

    public function testRemovedFieldValueIsFlaggedRemoved(): void
    {
        $this->plainTextField();
        $category = $this->makeCategory('Had Body', 'diff-remove', null, ['transportBody' => 'present']);
        $data = $this->serialize($category);

        // Incoming payload clears the body.
        $primary = $this->primarySiteHandle();
        $data['sites'][$primary]['fields']['transportBody'] = null;

        $result = $this->plugin()->differ->diffElement($data);

        $bodyEntry = null;
        foreach ($result->entries as $entry) {
            if ($entry->field === 'transportBody') {
                $bodyEntry = $entry;
            }
        }

        self::assertNotNull($bodyEntry);
        self::assertSame(DiffEntry::STATUS_REMOVED, $bodyEntry->status);
    }

    public function testDiffPackageProducesOneResultPerElement(): void
    {
        $this->plainTextField();
        $a = $this->makeCategory('A', 'diff-pkg-a');
        $b = $this->makeCategory('B', 'diff-pkg-b');
        $path = $this->export(['categories'], 'diff-pkg');
        $package = $this->plugin()->packages->open($path);

        $results = $this->plugin()->differ->diffPackage($package);

        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertInstanceOf(DiffResult::class, $result);
        }
    }

    public function testDiffEntriesCarryHumanReadableDisplays(): void
    {
        $this->plainTextField();
        $category = $this->makeCategory('Displayable', 'diff-display', null, ['transportBody' => 'old']);
        $data = $this->serialize($category);
        $data['sites'][$this->primarySiteHandle()]['fields']['transportBody'] = 'new';

        $result = $this->plugin()->differ->diffElement($data);

        $entry = null;
        foreach ($result->changes() as $e) {
            if ($e->field === 'transportBody') {
                $entry = $e;
            }
        }

        self::assertNotNull($entry);
        self::assertSame('old', $entry->oldDisplay);
        self::assertSame('new', $entry->newDisplay);
    }
}
