<?php

namespace justinholtweb\transport\models;

use craft\base\Model;

/**
 * Configuration describing what an export should include.
 */
class ExportConfig extends Model
{
    /**
     * @var string[] Package keys (element types) to export, e.g. ['entries', 'categories'].
     */
    public array $packageKeys = ['entries'];

    /**
     * @var int[] Explicit entry IDs to export. Takes precedence over section scoping.
     */
    public array $elementIds = [];

    /**
     * @var string|null Section handle to export entries from (when not selecting by id).
     */
    public ?string $section = null;

    /**
     * @var string|null Site handle to export from. Null = primary site.
     */
    public ?string $site = null;

    /**
     * @var bool Whether to bundle asset files (vs. metadata only).
     */
    public bool $includeAssetFiles = true;

    /**
     * @var string|null Optional package filename (without extension).
     */
    public ?string $packageName = null;

    /**
     * @var int|null The user to credit in history and notify on completion. Defaults to
     *               the logged-in user; queue jobs and console runs must set it, since
     *               they have no session of their own.
     */
    public ?int $userId = null;

    public function rules(): array
    {
        return [
            [['elementIds'], 'each', 'rule' => ['integer']],
            [['packageKeys'], 'each', 'rule' => ['string']],
            [['section', 'site', 'packageName'], 'string'],
            // A filename, nothing more: before 5.1.1 `../../web/site` wrote the package outside
            // the temp directory, somewhere it could be downloaded.
            [['packageName'], 'match', 'pattern' => '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', 'message' => 'Use letters, numbers, dots, dashes and underscores only.'],
            [['userId'], 'integer'],
            [['includeAssetFiles'], 'boolean'],
        ];
    }
}
