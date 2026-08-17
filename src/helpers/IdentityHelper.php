<?php

namespace justinholtweb\transport\helpers;

use craft\base\ElementInterface;
use justinholtweb\transport\elements\MatchesExistingElements;
use justinholtweb\transport\Plugin;

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
     * Resolves a UID to the local element instance within one site, or null.
     *
     * @param class-string<ElementInterface> $elementType
     */
    public static function resolveElementInSite(string $uid, string $elementType, int $siteId): ?ElementInterface
    {
        return $elementType::find()
            ->uid($uid)
            ->siteId($siteId)
            ->status(null)
            ->drafts(null)
            ->revisions(null)
            ->one();
    }

    /**
     * Resolves the element an incoming package element should be written to: the one
     * carrying its UID, or — when nothing does — the one its handler recognises as the
     * same content under a different UID.
     *
     * The fallback is what makes an import land on the single, category or asset that
     * already exists here rather than trying to create a second copy of it, which Craft
     * would either duplicate or refuse to save. It applies only to elements being
     * imported: references between elements are resolved by UID alone, through
     * {@see resolveElement()}, so a relation can never be silently repointed.
     *
     * @param array $data Serialized element payload.
     * @param int|null $siteId Restrict to one site, or null to match in any site.
     */
    public static function resolveImportTarget(array $data, ?int $siteId = null): ?ElementInterface
    {
        $uid = $data['uid'] ?? '';
        $type = $data['type'] ?? '';

        if ($uid === '' || $type === '') {
            return null;
        }

        $element = $siteId === null
            ? self::resolveElement($uid, $type)
            : self::resolveElementInSite($uid, $type, $siteId);

        if ($element !== null || !Plugin::getInstance()->getSettings()->matchExistingElements) {
            return $element;
        }

        $handler = Plugin::getInstance()->elementRegistry->getHandlerForType($type);

        return $handler instanceof MatchesExistingElements
            ? $handler->matchExisting($data, $siteId)
            : null;
    }

    /**
     * Whether the resolved element is the same content under a different UID, rather
     * than the UID the package carries — worth reporting, since it means the import is
     * adopting something it didn't create.
     */
    public static function isNaturalKeyMatch(array $data, ?ElementInterface $element): bool
    {
        return $element !== null
            && isset($data['uid'])
            && $element->uid !== $data['uid'];
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
