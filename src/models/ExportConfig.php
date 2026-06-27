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

    public function rules(): array
    {
        return [
            [['elementIds'], 'each', 'rule' => ['integer']],
            [['packageKeys'], 'each', 'rule' => ['string']],
            [['section', 'site', 'packageName'], 'string'],
            [['includeAssetFiles'], 'boolean'],
        ];
    }
}
