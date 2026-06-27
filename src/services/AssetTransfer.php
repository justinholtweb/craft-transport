<?php

namespace justinholtweb\transport\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use justinholtweb\transport\elements\AssetHandler;
use justinholtweb\transport\models\TransportPackage;
use justinholtweb\transport\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Bundles asset files into export packages and recreates them on import — from any
 * volume type, not just local.
 */
class AssetTransfer extends Component
{
    /**
     * Builds the in-zip-path => local-source-path map for any assets in the set, so
     * {@see PackageManager::write()} can add the real files.
     *
     * @param ElementInterface[] $elements
     * @return array<string, string>
     */
    public function filesForElements(array $elements): array
    {
        $files = [];

        foreach ($elements as $element) {
            if (!$element instanceof Asset) {
                continue;
            }
            try {
                $files[AssetHandler::filePathForAsset($element)] = $element->getCopyOfFile();
            } catch (Throwable $e) {
                Craft::warning("Couldn't bundle asset {$element->id}: {$e->getMessage()}", 'transport');
            }
        }

        return $files;
    }

    /**
     * Prepares a freshly-built (not yet saved) asset for creation by extracting its
     * bundled file from the package to a temp path. Returns false when the asset is new
     * but no file is available (caller should skip it).
     */
    public function stage(TransportPackage $package, array $data, Asset $asset): bool
    {
        // Existing assets only get metadata/field updates — leave the file in place.
        if ($asset->id !== null) {
            return true;
        }

        $inZipPath = AssetHandler::filePathFromAttributes($data['attributes'] ?? []);

        $tempDir = Plugin::getInstance()->getSettings()->getResolvedTempPath() . '/staged';
        FileHelper::createDirectory($tempDir);
        $destPath = $tempDir . '/' . ($data['attributes']['filename'] ?? 'file');

        if (!Plugin::getInstance()->packages->extractFileTo($package->path, "files/$inZipPath", $destPath)) {
            Craft::warning("No bundled file for new asset \"$inZipPath\"; skipping.", 'transport');
            return false;
        }

        $asset->tempFilePath = $destPath;
        $asset->newFolderId = $asset->folderId;
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        return true;
    }
}
