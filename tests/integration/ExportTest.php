<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use Craft;
use justinholtweb\transport\models\ExportConfig;
use justinholtweb\transport\records\ImportHistory;

/**
 * Export scoping (by section, by id, by type), dependency-order recording, and export
 * history.
 */
final class ExportTest extends TransportTestCase
{
    private function exportConfig(ExportConfig $config): string
    {
        return $this->trackPackage($this->plugin()->export->export($config));
    }

    public function testExportGathersRequestedType(): void
    {
        $this->makeCategory('One', 'exp-one');
        $this->makeCategory('Two', 'exp-two');

        $path = $this->export(['categories'], 'exp-type');
        $package = $this->plugin()->packages->open($path);

        self::assertCount(2, $package->getElementsByKey('categories'));
        self::assertSame([], $package->getElementsByKey('entries'));
    }

    public function testExportBySectionScopesEntries(): void
    {
        $this->makeEntry('In Section', 'exp-in-section');

        $config = new ExportConfig();
        $config->packageKeys = ['entries'];
        $config->section = self::SECTION;
        $config->packageName = 'exp-section';
        $path = $this->exportConfig($config);

        $package = $this->plugin()->packages->open($path);
        $slugs = [];
        foreach ($package->getElementsByKey('entries') as $entry) {
            $slugs[] = $entry['sites'][$this->primarySiteHandle()]['slug'] ?? null;
        }

        self::assertContains('exp-in-section', $slugs);
    }

    public function testExportByExplicitIdsScopesEntries(): void
    {
        $keep = $this->makeEntry('Keep', 'exp-id-keep');
        $this->makeEntry('Skip', 'exp-id-skip');

        $config = new ExportConfig();
        $config->packageKeys = ['entries'];
        $config->elementIds = [$keep->id];
        $config->packageName = 'exp-ids';
        $path = $this->exportConfig($config);

        $package = $this->plugin()->packages->open($path);
        $uids = array_column($package->getElementsByKey('entries'), 'uid');

        self::assertContains($keep->uid, $uids);
        self::assertCount(1, $uids);
    }

    public function testExportRecordsDependencyOrderParentBeforeChild(): void
    {
        $parent = $this->makeCategory('P', 'exp-dep-parent');
        $child = $this->makeCategory('C', 'exp-dep-child', $parent->id);

        $path = $this->export(['categories'], 'exp-dep');
        $order = $this->plugin()->packages->open($path)->getImportOrder();

        self::assertLessThan(
            array_search($child->uid, $order, true),
            array_search($parent->uid, $order, true)
        );
    }

    public function testExportWritesCompletedHistory(): void
    {
        $this->makeCategory('Logged', 'exp-log');

        $before = ImportHistory::find()->where(['direction' => 'export'])->count();
        $this->export(['categories'], 'exp-log');
        $after = ImportHistory::find()->where(['direction' => 'export'])->count();

        self::assertSame($before + 1, $after);

        $history = ImportHistory::find()
            ->where(['direction' => 'export'])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        self::assertSame(ImportHistory::STATUS_COMPLETED, $history->status);
        self::assertSame(1, $history->getCountsArray()['categories'] ?? null);
    }

    public function testBeforeExportEventCanCancel(): void
    {
        $this->makeCategory('Cancelled', 'exp-cancel');

        $handler = static function (\justinholtweb\transport\events\BeforeExportEvent $e) {
            $e->isValid = false;
        };
        $this->plugin()->export->on(
            \justinholtweb\transport\services\Export::EVENT_BEFORE_EXPORT,
            $handler
        );

        try {
            $config = new ExportConfig();
            $config->packageKeys = ['categories'];
            $path = $this->plugin()->export->export($config);
        } finally {
            $this->plugin()->export->off(
                \justinholtweb\transport\services\Export::EVENT_BEFORE_EXPORT,
                $handler
            );
        }

        self::assertSame('', $path, 'a cancelling listener yields no package');
    }
}
