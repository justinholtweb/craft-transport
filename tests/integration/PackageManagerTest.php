<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use Craft;
use craft\test\TestCase;
use justinholtweb\transport\models\TransportPackage;
use justinholtweb\transport\Plugin;
use yii\base\Exception;

/**
 * Reading, writing, validating, and extracting from Transport `.zip` packages.
 *
 * These don't need the fixture schema, so they extend Craft's plain TestCase and clean
 * up the files they write.
 */
final class PackageManagerTest extends TestCase
{
    /** @var string[] */
    private array $written = [];

    protected function _after(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        parent::_after();
    }

    private function packages(): \justinholtweb\transport\services\PackageManager
    {
        return Plugin::getInstance()->packages;
    }

    private function track(string $path): string
    {
        $this->written[] = $path;
        return $path;
    }

    public function testWriteThenOpenRoundTripsElementsAndManifest(): void
    {
        $elements = [
            'categories' => [
                ['uid' => 'uid-1', 'type' => 'craft\\elements\\Category', 'sites' => []],
                ['uid' => 'uid-2', 'type' => 'craft\\elements\\Category', 'sites' => []],
            ],
        ];

        $path = $this->track($this->packages()->write($elements, [], 'pm-roundtrip', [
            'importOrder' => ['uid-1', 'uid-2'],
        ]));

        self::assertFileExists($path);

        $package = $this->packages()->open($path);

        self::assertSame(TransportPackage::FORMAT_VERSION, $package->getFormatVersion());
        self::assertSame(Craft::$app->getVersion(), $package->getCraftVersion());
        self::assertSame(['categories' => 2], $package->getElementCounts());
        self::assertSame(['uid-1', 'uid-2'], $package->getImportOrder());
        self::assertCount(2, $package->getElementsByKey('categories'));
        self::assertCount(2, $package->allElements());
    }

    public function testManifestRecordsSitesAndExportMetadata(): void
    {
        $path = $this->track($this->packages()->write(['categories' => []], [], 'pm-manifest'));
        $package = $this->packages()->open($path);

        self::assertArrayHasKey('sites', $package->manifest);
        self::assertArrayHasKey($this->primaryHandle(), $package->manifest['sites']);
        self::assertArrayHasKey('exportedAt', $package->manifest);
        self::assertArrayHasKey('pluginVersions', $package->manifest);
        self::assertArrayHasKey('transport', $package->manifest['pluginVersions']);
    }

    public function testOpenMissingFileThrows(): void
    {
        $this->expectException(Exception::class);
        $this->packages()->open('/does/not/exist.zip');
    }

    public function testValidateAcceptsAFreshPackage(): void
    {
        $path = $this->track($this->packages()->write(['categories' => []], [], 'pm-valid'));
        $package = $this->packages()->open($path);

        self::assertSame([], $this->packages()->validate($package));
    }

    public function testValidateRejectsMissingFormatVersion(): void
    {
        $package = new TransportPackage(['manifest' => ['craftVersion' => '5.0.0']]);

        $errors = $this->packages()->validate($package);

        self::assertNotEmpty($errors);
    }

    public function testValidateRejectsNewerFormatVersion(): void
    {
        $package = new TransportPackage([
            'manifest' => [
                'version' => TransportPackage::FORMAT_VERSION + 1,
                'craftVersion' => '5.0.0',
            ],
        ]);

        self::assertNotEmpty($this->packages()->validate($package));
    }

    public function testValidateWarnsOnMissingCraftVersion(): void
    {
        $package = new TransportPackage([
            'manifest' => ['version' => TransportPackage::FORMAT_VERSION],
        ]);

        $errors = $this->packages()->validate($package);

        self::assertNotEmpty($errors);
    }

    public function testExtractFileTo(): void
    {
        // Stage a real source file, bundle it, then extract it back out.
        $sourceDir = sys_get_temp_dir() . '/transport-pm-src';
        @mkdir($sourceDir, 0777, true);
        $sourceFile = $sourceDir . '/hello.txt';
        file_put_contents($sourceFile, 'bundled bytes');

        $path = $this->track($this->packages()->write(
            ['assets' => []],
            ['myvolume/hello.txt' => $sourceFile],
            'pm-extract'
        ));

        $dest = sys_get_temp_dir() . '/transport-pm-out/hello.txt';
        @unlink($dest);

        $ok = $this->packages()->extractFileTo($path, 'files/myvolume/hello.txt', $dest);

        self::assertTrue($ok);
        self::assertSame('bundled bytes', file_get_contents($dest));

        @unlink($dest);
        @unlink($sourceFile);
    }

    public function testExtractMissingEntryReturnsFalse(): void
    {
        $path = $this->track($this->packages()->write(['categories' => []], [], 'pm-noentry'));

        $ok = $this->packages()->extractFileTo(
            $path,
            'files/nope.txt',
            sys_get_temp_dir() . '/transport-pm-out/nope.txt'
        );

        self::assertFalse($ok);
    }

    private function primaryHandle(): string
    {
        return Craft::$app->getSites()->getPrimarySite()->handle;
    }
}
