<?php

namespace justinholtweb\transport\tests\unit;

use justinholtweb\transport\models\TransportPackage;
use PHPUnit\Framework\TestCase;

class TransportPackageTest extends TestCase
{
    private function package(): TransportPackage
    {
        return new TransportPackage([
            'manifest' => [
                'version' => 1,
                'craftVersion' => '5.10.0',
                'elementCounts' => ['entries' => 2, 'categories' => 1],
                'importOrder' => ['uid-cat', 'uid-a', 'uid-b'],
            ],
            'elements' => [
                'entries' => [['uid' => 'uid-a'], ['uid' => 'uid-b']],
                'categories' => [['uid' => 'uid-cat']],
            ],
        ]);
    }

    public function testManifestAccessors(): void
    {
        $package = $this->package();

        $this->assertSame(1, $package->getFormatVersion());
        $this->assertSame('5.10.0', $package->getCraftVersion());
        $this->assertSame(['entries' => 2, 'categories' => 1], $package->getElementCounts());
        $this->assertSame(['uid-cat', 'uid-a', 'uid-b'], $package->getImportOrder());
    }

    public function testElementsByKey(): void
    {
        $package = $this->package();

        $this->assertCount(2, $package->getElementsByKey('entries'));
        $this->assertCount(1, $package->getElementsByKey('categories'));
        $this->assertSame([], $package->getElementsByKey('missing'));
    }

    public function testAllElementsFlattensEveryType(): void
    {
        $uids = array_column($this->package()->allElements(), 'uid');

        $this->assertEqualsCanonicalizing(['uid-a', 'uid-b', 'uid-cat'], $uids);
    }

    public function testEmptyPackageAllElements(): void
    {
        $this->assertSame([], (new TransportPackage())->allElements());
    }
}
