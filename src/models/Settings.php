<?php

namespace justinholtweb\transport\models;

use Craft;
use craft\base\Model;

/**
 * Transport plugin settings.
 */
class Settings extends Model
{
    /**
     * @var string Path (relative to Craft's base path, or absolute) used for staging
     *             package files during export/import. Supports environment variables.
     */
    public string $tempPath = '@storage/transport';

    /**
     * @var int Maximum package size in megabytes that may be uploaded for import.
     */
    public int $maxPackageSize = 512;

    /**
     * @var bool Whether asset files are bundled into export packages by default.
     *           When false, only asset metadata is exported.
     */
    public bool $includeAssetFiles = true;

    /**
     * @var int Number of days to retain pre-import snapshots before pruning.
     */
    public int $snapshotRetentionDays = 30;

    /**
     * @var int Maximum number of imports to retain snapshots for, regardless of age.
     */
    public int $snapshotRetentionCount = 20;

    /**
     * @var string Log verbosity. One of: error, warning, info, debug.
     */
    public string $logLevel = 'info';

    /**
     * Resolves {@see $tempPath} to an absolute filesystem path with aliases parsed.
     */
    public function getResolvedTempPath(): string
    {
        return Craft::getAlias($this->tempPath);
    }

    public function rules(): array
    {
        return [
            [['tempPath', 'logLevel'], 'required'],
            [['maxPackageSize', 'snapshotRetentionDays', 'snapshotRetentionCount'], 'integer', 'min' => 0],
            [['includeAssetFiles'], 'boolean'],
            [['logLevel'], 'in', 'range' => ['error', 'warning', 'info', 'debug']],
        ];
    }
}
