<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use Craft;
use craft\elements\Asset;
use craft\helpers\Assets as AssetsHelper;

/**
 * Asset file bundling on export and recreation on import — the one flow that moves real
 * bytes, not just metadata.
 */
final class AssetTransferTest extends TransportTestCase
{
    /**
     * Creates a real asset in the throwaway volume from an in-memory image.
     */
    private function makeAsset(string $filename, string $contents): Asset
    {
        $volume = $this->volume();
        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);

        $tempPath = sys_get_temp_dir() . '/transport-asset-src-' . $filename;
        file_put_contents($tempPath, $contents);

        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->setFilename($filename);
        $asset->newFolderId = $folder->id;
        $asset->setVolumeId($volume->id);
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            self::fail('Could not save asset: ' . implode('; ', $asset->getFirstErrors()));
        }

        @unlink($tempPath);
        return $asset;
    }

    protected function _before(): void
    {
        parent::_before();
        // In this harness @storage lives under tests/, which Craft treats as a system
        // directory — so the default staging path (@storage/transport/staged) is
        // rejected when relocating an asset's file. Point staging at the OS temp dir,
        // an always-allowed root, mirroring a real install where @storage is valid.
        $this->plugin()->getSettings()->tempPath = sys_get_temp_dir() . '/transport-pkg';
    }

    private function deleteAssets(): void
    {
        foreach (Asset::find()->volume(self::VOLUME)->status(null)->all() as $asset) {
            Craft::$app->getElements()->deleteElement($asset, true);
        }
    }

    protected function _after(): void
    {
        $this->deleteAssets();
        parent::_after();
    }

    public function testExportBundlesTheAssetFile(): void
    {
        $asset = $this->makeAsset('bundle.txt', 'hello asset');

        $files = $this->plugin()->assets->filesForElements([$asset]);

        self::assertNotEmpty($files);
        $inZipPath = array_key_first($files);
        self::assertStringContainsString('bundle.txt', $inZipPath);
        self::assertFileExists($files[$inZipPath]);
    }

    public function testAssetFilePathIsVolumeRelative(): void
    {
        $asset = $this->makeAsset('pathcheck.txt', 'x');

        $path = \justinholtweb\transport\elements\AssetHandler::filePathForAsset($asset);

        self::assertStringStartsWith(self::VOLUME . '/', $path);
        self::assertStringEndsWith('pathcheck.txt', $path);
    }

    public function testDeletedAssetIsRecreatedFromBundle(): void
    {
        $asset = $this->makeAsset('recreate.txt', 'recreate me');
        $uid = $asset->uid;
        $path = $this->export(['assets'], 'asset-recreate', ['includeAssetFiles' => true]);

        // Wipe the asset (and its file) to simulate a fresh target.
        $this->deleteAssets();
        self::assertNull(Asset::find()->uid($uid)->status(null)->one());

        $result = $this->plugin()->import->importPackage($path, false);

        self::assertSame('completed', $result['status'], implode('; ', $result['errors']));
        self::assertSame(1, $result['created']);

        $recreated = Asset::find()->volume(self::VOLUME)->status(null)->one();
        self::assertNotNull($recreated);
        self::assertSame('recreate.txt', $recreated->getFilename());
        self::assertSame('recreate me', stream_get_contents($recreated->getStream()));
    }

    public function testNewAssetWithoutBundledFileIsSkipped(): void
    {
        // Export metadata only (no bundled bytes), then delete and import: a brand-new
        // asset with no file available must be skipped rather than saved half-formed.
        $this->makeAsset('nofile.txt', 'orphan');
        $path = $this->export(['assets'], 'asset-nofile', ['includeAssetFiles' => false]);
        $this->deleteAssets();

        $result = $this->plugin()->import->importPackage($path, false);

        self::assertSame(0, $result['created']);
        self::assertSame(1, $result['skipped']);
    }

    public function testExistingAssetGetsMetadataUpdateWithoutFile(): void
    {
        $asset = $this->makeAsset('altme.txt', 'content');
        $asset->alt = 'original alt';
        Craft::$app->getElements()->saveElement($asset);

        $path = $this->export(['assets'], 'asset-alt', ['includeAssetFiles' => false]);

        // The asset still exists; import should update it in place (alt carried through).
        $result = $this->plugin()->import->importPackage($path, false);

        self::assertSame(0, $result['created']);
        self::assertSame(1, $result['updated']);
    }
}
