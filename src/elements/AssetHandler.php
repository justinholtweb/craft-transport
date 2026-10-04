<?php

namespace justinholtweb\transport\elements;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Assets;

/**
 * Element handler for assets.
 *
 * Serializes the volume + folder path + filename so the file can be recreated in the
 * target. The actual file bytes are bundled/extracted by
 * {@see \justinholtweb\transport\services\AssetTransfer}.
 */
class AssetHandler extends BaseElementHandler
{
    public function elementType(): string
    {
        return Asset::class;
    }

    public function packageKey(): string
    {
        return 'assets';
    }

    public function query(): ElementQueryInterface
    {
        return Asset::find()->kind('*')->status(null);
    }

    public function serializeAttributes(ElementInterface $element): array
    {
        /** @var Asset $element */
        return [
            'volume' => $element->getVolume()->handle,
            'folderPath' => $element->getFolder()->path ?? '',
            'filename' => $element->getFilename(),
            'kind' => $element->kind,
            'alt' => $element->alt,
            'size' => $element->size,
        ];
    }

    public function makeElement(array $attributes): ?ElementInterface
    {
        $volume = isset($attributes['volume'])
            ? Craft::$app->getVolumes()->getVolumeByHandle($attributes['volume'])
            : null;

        if (!$volume) {
            return null;
        }

        $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume(
            $attributes['folderPath'] ?? '',
            $volume
        );

        $asset = new Asset();
        $asset->setVolumeId($volume->id);
        $asset->folderId = $folder->id;
        if (!empty($attributes['filename'])) {
            $asset->setFilename(self::baseFilename($attributes['filename']));
        }

        return $asset;
    }

    /**
     * An asset is identified by its filename inside its folder — the same identity the
     * filesystem enforces, and what would otherwise be uploaded a second time as
     * "file-1.jpg".
     */
    public function matchExisting(array $data, ?int $siteId = null): ?ElementInterface
    {
        $attributes = $data['attributes'] ?? [];
        $filename = !empty($attributes['filename']) ? self::baseFilename($attributes['filename']) : null;

        $volume = isset($attributes['volume'])
            ? Craft::$app->getVolumes()->getVolumeByHandle($attributes['volume'])
            : null;

        if (!$filename || !$volume) {
            return null;
        }

        $folder = Craft::$app->getAssets()->findFolder([
            'volumeId' => $volume->id,
            'path' => $attributes['folderPath'] ?? '',
        ]);

        if (!$folder) {
            return null;
        }

        $query = Asset::find()
            ->folderId($folder->id)
            ->filename($filename)
            ->kind('*')
            ->status(null)
            ->orderBy(['elements.id' => SORT_ASC]);

        return $this->scopeToSite($query, $siteId)->one();
    }

    public function applyAttributes(array $attributes, ElementInterface $element): void
    {
        /** @var Asset $element */
        if (array_key_exists('alt', $attributes)) {
            $element->alt = $attributes['alt'];
        }
    }

    /**
     * The in-package path for an asset's file, derived from serialized attributes.
     */
    /**
     * A package's filename reduced to a name, the way the staged file is named. The package is
     * untrusted, so `../../x.txt` is `x.txt` here.
     */
    public static function baseFilename(string $filename): string
    {
        return Assets::prepareAssetName(basename(str_replace('\\', '/', $filename)));
    }

    public static function filePathFromAttributes(array $attributes): string
    {
        $volume = $attributes['volume'] ?? 'volume';
        $folderPath = $attributes['folderPath'] ?? '';
        $filename = $attributes['filename'] ?? '';

        return rtrim($volume . '/' . $folderPath, '/') . '/' . $filename;
    }

    /**
     * The in-package path for a live asset's file.
     */
    public static function filePathForAsset(Asset $asset): string
    {
        return self::filePathFromAttributes([
            'volume' => $asset->getVolume()->handle,
            'folderPath' => $asset->getFolder()->path ?? '',
            'filename' => $asset->getFilename(),
        ]);
    }
}
