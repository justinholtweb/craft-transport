<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use Craft;
use justinholtweb\transport\models\ExportConfig;
use justinholtweb\transport\records\ImportHistory;

/**
 * The service behaviour the console's CP-equivalent commands rely on: element IDs that
 * narrow every type, "with dependencies" exports, and the rollback plan behind
 * `transport/rollback --dry-run`. The commands themselves are run for real by
 * tests/harness/console.php.
 */
final class ConsoleParityTest extends TransportTestCase
{
    private function exportConfig(ExportConfig $config): string
    {
        return $this->trackPackage($this->plugin()->export->export($config));
    }

    public function testElementIdsNarrowEveryType(): void
    {
        $keep = $this->makeCategory('Keep', 'cp-ids-keep');
        $this->makeCategory('Skip', 'cp-ids-skip');

        $config = new ExportConfig();
        $config->packageKeys = ['categories', 'entries'];
        $config->elementIds = [$keep->id];
        $config->packageName = 'cp-ids';
        $package = $this->plugin()->packages->open($this->exportConfig($config));

        self::assertSame([$keep->uid], array_column($package->getElementsByKey('categories'), 'uid'));
        self::assertSame([], $package->getElementsByKey('entries'));
    }

    public function testIncludeDependenciesPullsInReferencedElements(): void
    {
        $grandparent = $this->makeCategory('Grandparent', 'cp-dep-gp');
        $parent = $this->makeCategory('Parent', 'cp-dep-parent', $grandparent->id);
        $child = $this->makeCategory('Child', 'cp-dep-child', $parent->id);

        $config = new ExportConfig();
        $config->packageKeys = ['categories'];
        $config->elementIds = [$child->id];
        $config->packageName = 'cp-dep-off';
        $without = $this->plugin()->packages->open($this->exportConfig($config));
        self::assertSame([$child->uid], array_column($without->getElementsByKey('categories'), 'uid'));

        // Transitively: the child needs its parent, which needs its own.
        $config->includeDependencies = true;
        $config->packageName = 'cp-dep-on';
        $with = $this->plugin()->packages->open($this->exportConfig($config));
        $uids = array_column($with->getElementsByKey('categories'), 'uid');

        self::assertEqualsCanonicalizing([$grandparent->uid, $parent->uid, $child->uid], $uids);

        $order = $with->getImportOrder();
        self::assertLessThan(array_search($parent->uid, $order, true), array_search($grandparent->uid, $order, true));
        self::assertLessThan(array_search($child->uid, $order, true), array_search($parent->uid, $order, true));
    }

    public function testIncludeDependenciesIsValidatedAsBoolean(): void
    {
        $config = new ExportConfig();
        $config->includeDependencies = true;

        self::assertTrue($config->validate(['includeDependencies']));
    }

    public function testRollbackPlanListsWithoutChangingAnything(): void
    {
        $body = $this->plainTextField();
        $category = $this->makeCategory('Planned', 'cp-plan', null, [$body->handle => 'original']);
        $this->makeCategory('Created', 'cp-plan-new');
        $path = $this->export(['categories'], 'cp-plan');

        // One element diverges locally, the other is gone: the import updates one, creates one.
        $category->setFieldValue($body->handle, 'local edit');
        Craft::$app->getElements()->saveElement($category);
        Craft::$app->getElements()->deleteElement($this->findCategory('cp-plan-new'), true);
        $this->plugin()->import->importPackage($path, false);

        $history = ImportHistory::find()->where(['direction' => 'import'])->orderBy(['id' => SORT_DESC])->one();
        $plan = $this->plugin()->snapshots->plan($history);

        self::assertSame([], $plan['errors']);
        self::assertCount(1, $plan['restore']);
        self::assertCount(1, $plan['delete']);
        self::assertStringContainsString('Created', $plan['delete'][0]);
        self::assertSame([], $plan['refused']);

        // Nothing moved: the import's values are still in place and the row isn't rolled back.
        self::assertSame('original', $this->findCategory('cp-plan')->getFieldValue($body->handle));
        self::assertNotNull($this->findCategory('cp-plan-new'));
        self::assertSame(ImportHistory::STATUS_COMPLETED, ImportHistory::findOne($history->id)->status);
    }

    public function testRollbackPlanWithoutSnapshotReportsIt(): void
    {
        $history = new ImportHistory();
        $history->packageName = 'orphan.zip';
        $history->direction = ImportHistory::DIRECTION_IMPORT;
        $history->status = ImportHistory::STATUS_COMPLETED;
        $history->save(false);

        self::assertNotEmpty($this->plugin()->snapshots->plan($history)['errors']);
    }
}
