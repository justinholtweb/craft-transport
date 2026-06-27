<?php

namespace justinholtweb\transport\helpers;

use craft\base\ElementInterface;

/**
 * UID-based identity resolution.
 *
 * Transport never trusts environment-local element IDs across a package boundary.
 * Every reference is stored as a UID and resolved back to a local ID on import.
 */
class IdentityHelper
{
    /** @var array<string, int|null> Per-request cache, keyed by "type:uid". */
    private static array $cache = [];

    /**
     * Resolves a UID to a local element ID for the given element type, or null when
     * no matching element exists in this environment.
     *
     * @param class-string<ElementInterface> $elementType
     */
    public static function resolveId(string $uid, string $elementType): ?int
    {
        $key = $elementType . ':' . $uid;

        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = self::resolveElement($uid, $elementType)?->id;
        }

        return self::$cache[$key];
    }

    /**
     * Resolves a UID to the local element instance (any site, any status), or null.
     *
     * @param class-string<ElementInterface> $elementType
     */
    public static function resolveElement(string $uid, string $elementType): ?ElementInterface
    {
        return $elementType::find()
            ->uid($uid)
            ->status(null)
            ->site('*')
            ->unique()
            ->one();
    }

    /**
     * Clears the per-request resolution cache. Call between import batches that may
     * create new elements other references depend on.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
