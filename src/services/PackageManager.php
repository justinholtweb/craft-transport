<?php

namespace justinholtweb\transport\services;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use justinholtweb\transport\models\TransportPackage;
use justinholtweb\transport\Plugin;
use yii\base\Component;
use yii\base\Exception;
use ZipArchive;

/**
 * Creates, reads, and validates Transport `.zip` packages.
 *
 * Package layout:
 *   manifest.json          — metadata (format version, Craft/plugin versions, counts)
 *   elements/<key>.json    — serialized elements, one file per element type
 *   files/<volume>/<path>  — bundled asset files (Phase 2)
 */
class PackageManager extends Component
{
    /**
     * Writes a package zip from grouped serialized elements and returns its path.
     *
     * @param array<string, array> $elementsByKey Serialized elements keyed by package key.
     * @param array<string, string> $files Map of in-zip path => absolute source file path.
     */
    public function write(array $elementsByKey, array $files = [], ?string $name = null, array $manifestExtra = []): string
    {
        $tempPath = Plugin::getInstance()->getSettings()->getResolvedTempPath();
        FileHelper::createDirectory($tempPath);

        $filename = ($name ?: 'transport-' . gmdate('Ymd-His')) . '.zip';
        $path = $tempPath . DIRECTORY_SEPARATOR . $filename;

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Unable to create package at $path");
        }

        $counts = [];
        foreach ($elementsByKey as $key => $elements) {
            $counts[$key] = count($elements);
            $zip->addFromString("elements/$key.json", Json::encode($elements, JSON_PRETTY_PRINT));
        }

        $manifest = array_merge($this->buildManifest($counts), $manifestExtra);
        $zip->addFromString('manifest.json', Json::encode($manifest, JSON_PRETTY_PRINT));

        foreach ($files as $inZipPath => $sourcePath) {
            if (is_file($sourcePath)) {
                $zip->addFile($sourcePath, "files/$inZipPath");
            }
        }

        $zip->close();

        return $path;
    }

    /**
     * Opens and decodes a package zip into a {@see TransportPackage}.
     */
    public function open(string $path): TransportPackage
    {
        if (!is_file($path)) {
            throw new Exception("Package not found: $path");
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new Exception("Unable to open package: $path");
        }

        $manifestJson = $zip->getFromName('manifest.json');
        if ($manifestJson === false) {
            $zip->close();
            throw new Exception('Package is missing manifest.json');
        }
        $manifest = Json::decode($manifestJson);

        $elements = [];
        foreach (array_keys($manifest['elementCounts'] ?? []) as $key) {
            $json = $zip->getFromName("elements/$key.json");
            if ($json !== false) {
                $elements[$key] = Json::decode($json);
            }
        }

        $zip->close();

        return new TransportPackage([
            'path' => $path,
            'manifest' => $manifest,
            'elements' => $elements,
        ]);
    }

    /**
     * Extracts a single file from a package zip to a destination path on disk.
     * Returns false when the entry doesn't exist in the package.
     */
    public function extractFileTo(string $packagePath, string $inZipPath, string $destPath): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) {
            return false;
        }

        $stream = $zip->getStream($inZipPath);
        if ($stream === false) {
            $zip->close();
            return false;
        }

        FileHelper::createDirectory(dirname($destPath));
        $out = fopen($destPath, 'wb');
        if ($out === false) {
            fclose($stream);
            $zip->close();
            return false;
        }

        stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);
        $zip->close();

        return true;
    }

    /**
     * Validates basic package integrity and returns a list of human-readable problems.
     * An empty array means the package looks importable.
     *
     * @return string[]
     */
    public function validate(TransportPackage $package): array
    {
        $errors = [];

        $version = $package->getFormatVersion();
        if ($version === null) {
            $errors[] = Craft::t('transport', 'Package has no format version.');
        } elseif ($version > TransportPackage::FORMAT_VERSION) {
            $errors[] = Craft::t('transport', 'Package was created by a newer version of Transport.');
        }

        if (!$package->getCraftVersion()) {
            $errors[] = Craft::t('transport', 'Package does not declare a source Craft version.');
        }

        return $errors;
    }

    private function buildManifest(array $counts): array
    {
        $sites = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $sites[$site->handle] = [
                'name' => $site->getName(),
                'language' => $site->language,
                'primary' => $site->primary,
            ];
        }

        return [
            'version' => TransportPackage::FORMAT_VERSION,
            'craftVersion' => Craft::$app->getVersion(),
            'pluginVersions' => $this->installedPluginVersions(),
            'sites' => $sites,
            'exportedAt' => gmdate(DATE_ATOM),
            'exportedBy' => Craft::$app->getUser()->getIdentity()?->username,
            'elementCounts' => $counts,
        ];
    }

    private function installedPluginVersions(): array
    {
        $versions = [];
        foreach (Craft::$app->getPlugins()->getAllPlugins() as $plugin) {
            $versions[$plugin->handle] = $plugin->getVersion();
        }
        return $versions;
    }
}
