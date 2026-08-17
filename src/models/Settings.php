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
     * @var bool Whether an import may match an element that already exists here under a
     *           different UID — a single by its section, a slug within its section or
     *           group, an asset's filename in its folder, a user's email — and update it
     *           instead of trying to add a second copy.
     *
     *           Turn this off for strict UID-only identity, accepting that content which
     *           exists in both environments under different UIDs will be duplicated, or
     *           will fail to import where Craft can't generate a unique URI for it.
     */
    public bool $matchExistingElements = true;

    /**
     * @var bool Whether the "email me when this finishes" box is ticked by default on
     *           the export and import screens.
     */
    public bool $notifyOnCompletion = true;

    /**
     * @var string Additional addresses to copy on every completion email, separated by
     *             commas. Supports environment variables. The user who started the run
     *             is always notified when they ask to be.
     */
    public string $notificationEmails = '';

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
            [['includeAssetFiles', 'notifyOnCompletion', 'matchExistingElements'], 'boolean'],
            [['notificationEmails'], 'string'],
            [['logLevel'], 'in', 'range' => ['error', 'warning', 'info', 'debug']],
        ];
    }
}
