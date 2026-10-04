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

        // The filename comes from the package, so it is untrusted: before 5.1.1 a name like
        // `../../web/x.php` was written wherever it pointed, even on a dry run. Only its base
        // name is used, made safe the way Craft makes an upload's, and it must have an extension
        // this site accepts — the file is refused before any of it reaches the disk.
        $filename = self::safeFilename((string)($data['attributes']['filename'] ?? ''));
        if ($filename === null) {
            Craft::warning("Refused bundled file for new asset \"$inZipPath\": its filename or type isn't allowed.", 'transport');
            return false;
        }

        // Each staged file gets a directory of its own, so two assets with the same name can't
        // overwrite each other.
        $tempDir = Plugin::getInstance()->getSettings()->getResolvedTempPath() . '/staged/' . bin2hex(random_bytes(8));
        FileHelper::createDirectory($tempDir);
        $destPath = $tempDir . '/' . $filename;

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

    /**
     * A package's asset filename made safe to write, or null when it can't be: no directory
     * parts, no leading dot, and an extension in the site's `allowedFileExtensions`.
     */
    public static function safeFilename(string $filename): ?string
    {
        $filename = AssetHandler::baseFilename($filename);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($filename === '' || str_starts_with($filename, '.') || $extension === '') {
            return null;
        }

        $allowed = array_map('strtolower', Craft::$app->getConfig()->getGeneral()->allowedFileExtensions);

        return in_array($extension, $allowed, true) ? $filename : null;
    }
}
