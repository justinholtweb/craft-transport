<?php

namespace justinholtweb\transport\models;

use craft\base\Model;

/**
 * The element-level diff between a package element and the target environment, holding
 * a list of {@see DiffEntry} field differences.
 */
class DiffResult extends Model
{
    public const ACTION_ADD = 'add';
    public const ACTION_UPDATE = 'update';
    public const ACTION_UNCHANGED = 'unchanged';

    public string $uid = '';
    public string $type = '';
    public string $key = '';
    public string $title = '';

    /** @var string One of the ACTION_* constants. */
    public string $action = self::ACTION_UNCHANGED;

    /** @var bool Whether a matching element already exists in the target. */
    public bool $exists = false;

    /** @var DiffEntry[] */
    public array $entries = [];

    /**
     * @return DiffEntry[] Only the entries that represent an actual change.
     */
    public function changes(): array
    {
        return array_values(array_filter($this->entries, static fn(DiffEntry $e) => $e->isChange()));
    }

    public function changeCount(): int
    {
        return count($this->changes());
    }
}
