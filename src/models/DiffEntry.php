<?php

namespace justinholtweb\transport\models;

use craft\base\Model;

/**
 * A single field-level difference between a package element and its target counterpart.
 */
class DiffEntry extends Model
{
    public const STATUS_ADDED = 'added';
    public const STATUS_CHANGED = 'changed';
    public const STATUS_REMOVED = 'removed';
    public const STATUS_UNCHANGED = 'unchanged';

    /** @var string Site handle this difference belongs to. */
    public string $site = '';

    /** @var string Field handle (or pseudo-field like "title"/"slug"). */
    public string $field = '';

    /** @var string One of the STATUS_* constants. */
    public string $status = self::STATUS_UNCHANGED;

    /** @var mixed Raw current (target) value. */
    public mixed $oldValue = null;

    /** @var mixed Raw incoming (package) value. */
    public mixed $newValue = null;

    /** @var string Human-readable current value. */
    public string $oldDisplay = '';

    /** @var string Human-readable incoming value. */
    public string $newDisplay = '';

    /**
     * A stable key identifying this field within its element, used for merge decisions.
     */
    public function path(): string
    {
        return $this->site . '.' . $this->field;
    }

    public function isChange(): bool
    {
        return $this->status !== self::STATUS_UNCHANGED;
    }
}
