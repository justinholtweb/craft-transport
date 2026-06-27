<?php

namespace justinholtweb\transport\models;

use craft\base\Model;

/**
 * In-memory representation of an opened Transport package (a `.zip` on disk).
 */
class TransportPackage extends Model
{
    /** Current package format version. */
    public const FORMAT_VERSION = 1;

    /**
     * @var string|null Absolute path to the package zip on disk.
     */
    public ?string $path = null;

    /**
     * @var array Decoded `manifest.json` contents.
     */
    public array $manifest = [];

    /**
     * @var array<string, array> Serialized elements keyed by package key (e.g. "entries").
     */
    public array $elements = [];

    public function getFormatVersion(): ?int
    {
        return $this->manifest['version'] ?? null;
    }

    public function getCraftVersion(): ?string
    {
        return $this->manifest['craftVersion'] ?? null;
    }

    public function getElementCounts(): array
    {
        return $this->manifest['elementCounts'] ?? [];
    }

    /**
     * Dependency-safe import order (list of element UIDs), if recorded.
     *
     * @return string[]
     */
    public function getImportOrder(): array
    {
        return $this->manifest['importOrder'] ?? [];
    }

    /**
     * @return array Serialized elements for a given package key.
     */
    public function getElementsByKey(string $key): array
    {
        return $this->elements[$key] ?? [];
    }

    /**
     * Returns a flat list of every serialized element across all keys.
     */
    public function allElements(): array
    {
        return array_merge(...array_values($this->elements ?: [[]]));
    }
}
