<?php

namespace justinholtweb\transport\elements;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;

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
            $asset->setFilename($attributes['filename']);
        }

        return $asset;
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
