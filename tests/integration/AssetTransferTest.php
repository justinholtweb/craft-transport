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

    /**
     * Rewrites one asset's filename inside an exported package, and adds the bundled file under
     * the in-zip path that name produces — what a hand-built malicious package looks like.
     */
    private function tamperFilename(string $path, string $filename, string $contents): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $assets = json_decode((string)$zip->getFromName('elements/assets.json'), true);
        $assets[0]['attributes']['filename'] = $filename;
        $zip->addFromString('elements/assets.json', json_encode($assets));
        $zip->addFromString('files/' . \justinholtweb\transport\elements\AssetHandler::filePathFromAttributes($assets[0]['attributes']), $contents);
        $zip->close();
    }

    public function testAPackageFilenameCannotWriteOutsideTheStagingDirectory(): void
    {
        $this->makeAsset('innocent.txt', 'x');
        $path = $this->export(['assets'], 'asset-slip', ['includeAssetFiles' => true]);
        $this->deleteAssets();

        // Three levels up from <temp>/staged/<random>/ lands in the temp path's parent.
        $name = 'slip-' . bin2hex(random_bytes(4)) . '.php';
        $this->tamperFilename($path, '../../../' . $name, '<?php echo "pwned";');
        $escaped = dirname($this->plugin()->getSettings()->getResolvedTempPath()) . '/' . $name;
        $escapedOld = dirname($this->plugin()->getSettings()->getResolvedTempPath() . '/staged') . '/../' . $name;

        // A dry run used to stage the file too.
        $this->plugin()->import->importPackage($path, true);
        $result = $this->plugin()->import->importPackage($path, false);

        self::assertFileDoesNotExist($escaped);
        self::assertFileDoesNotExist($escapedOld);
        self::assertSame(0, $result['created']);
        self::assertSame(1, $result['skipped']);
    }

    public function testATraversingButHarmlessNameIsImportedUnderItsBaseName(): void
    {
        $this->makeAsset('innocent.txt', 'x');
        $path = $this->export(['assets'], 'asset-base', ['includeAssetFiles' => true]);
        $this->deleteAssets();
        $this->tamperFilename($path, '../../moved.txt', 'kept');

        $result = $this->plugin()->import->importPackage($path, false);

        self::assertSame(1, $result['created'], implode('; ', $result['errors']));
        $asset = Asset::find()->volume(self::VOLUME)->status(null)->one();
        self::assertSame('kept', stream_get_contents($asset->getStream()));
        self::assertStringNotContainsString('..', (string)$asset->getFilename());
    }

    public function testSafeFilename(): void
    {
        $safe = [\justinholtweb\transport\services\AssetTransfer::class, 'safeFilename'];

        self::assertSame('photo.jpg', $safe('photo.jpg'));
        self::assertSame('x.txt', $safe('../../x.txt'));
        self::assertSame('x.txt', $safe('..\\..\\x.txt'));
        self::assertNull($safe('../../web/shell.php'));
        self::assertNull($safe('.htaccess'));
        self::assertNull($safe('noextension'));
        self::assertNull($safe(''));
    }
}
