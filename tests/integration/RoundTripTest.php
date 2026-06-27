<?php

namespace justinholtweb\transport\tests\integration;

use Craft;

/**
 * Full export → import round trips against a live Craft application.
 */
class RoundTripTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteAllTestCategories();
    }

    protected function tearDown(): void
    {
        $this->deleteAllTestCategories();
        parent::tearDown();
    }

    public function testPluginIsLoaded(): void
    {
        $this->assertNotNull($this->plugin(), 'Transport plugin should be available');
        $this->assertNotNull($this->plugin()->serializer);
    }

    public function testSerializesWithUidBasedIdentity(): void
    {
        $category = $this->makeCategory('Round Trip', 'rt-one');
        $data = $this->plugin()->serializer->serializeElement($category);

        $this->assertSame($category->uid, $data['uid']);
        $this->assertSame('categories', $data['key']);
        $this->assertSame('Round Trip', $data['sites'][Craft::$app->getSites()->getPrimarySite()->handle]['title']);
    }

    public function testDeleteThenImportRecreatesElement(): void
    {
        $category = $this->makeCategory('Recreate Me', 'rt-recreate');
        $uid = $category->uid;
        $path = $this->exportCategories('it-recreate');

        // Simulate a fresh target environment.
        $this->deleteAllTestCategories();
        $this->assertNull($this->findCategory('rt-recreate'));

        $result = $this->plugin()->import->importPackage($path, false);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, $result['created']);
        $recreated = $this->findCategory('rt-recreate');
        $this->assertNotNull($recreated);
        $this->assertSame($uid, $recreated->uid, 'UID is preserved across the round trip');
        $this->assertSame('Recreate Me', $recreated->title);

        @unlink($path);
    }

    public function testStructureParentImportsBeforeChild(): void
    {
        $parent = $this->makeCategory('Parent', 'rt-parent');
        $child = $this->makeCategory('Child', 'rt-child', $parent->id);
        $path = $this->exportCategories('it-structure');

        // The dependency order must list the parent before the child.
        $package = $this->plugin()->packages->open($path);
        $order = $package->getImportOrder();
        $this->assertLessThan(
            array_search($child->uid, $order, true),
            array_search($parent->uid, $order, true),
            'parent must be ordered before child'
        );

        $this->deleteAllTestCategories();
        $this->plugin()->import->importPackage($path, false);

        $recreatedChild = $this->findCategory('rt-child');
        $this->assertNotNull($recreatedChild);
        $this->assertSame('Parent', $recreatedChild->getParent()?->title, 'child relinked to its parent');

        @unlink($path);
    }

    public function testSelectiveMergeRejectsAFieldChange(): void
    {
        $category = $this->makeCategory('Original Title', 'rt-merge');
        $path = $this->exportCategories('it-merge');

        // Diverge the target, then import with the title change rejected.
        $category->title = 'Locally Edited';
        Craft::$app->getElements()->saveElement($category);

        $primary = Craft::$app->getSites()->getPrimarySite()->handle;
        $this->plugin()->import->importPackage($path, false, [], [
            'decisions' => [$category->uid => ["$primary.title"]],
        ]);

        $this->assertSame('Locally Edited', $this->findCategory('rt-merge')->title, 'rejected field kept local value');

        // Now accept it.
        $this->plugin()->import->importPackage($path, false);
        $this->assertSame('Original Title', $this->findCategory('rt-merge')->title, 'accepted field applied package value');

        @unlink($path);
    }

    public function testRollbackRestoresPriorState(): void
    {
        $category = $this->makeCategory('Before Import', 'rt-rollback');
        $path = $this->exportCategories('it-rollback');

        $category->title = 'After Local Edit';
        Craft::$app->getElements()->saveElement($category);

        // Import (sets title back to "Before Import"), then roll it back.
        $this->plugin()->import->importPackage($path, false);
        $this->assertSame('Before Import', $this->findCategory('rt-rollback')->title);

        $history = \justinholtweb\transport\records\ImportHistory::find()
            ->where(['direction' => 'import'])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $rollback = $this->plugin()->snapshots->rollback($history);

        $this->assertSame('completed', $rollback['status']);
        $this->assertSame('After Local Edit', $this->findCategory('rt-rollback')->title, 'rollback restored prior state');

        @unlink($path);
    }
}
