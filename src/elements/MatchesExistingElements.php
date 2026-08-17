<?php

namespace justinholtweb\transport\elements;

use craft\base\ElementInterface;

/**
 * Lets an element handler recognise an element that already exists in the target under
 * a different UID.
 *
 * Transport identifies content by UID, but the same content routinely exists in two
 * environments with different UIDs — a single that was created independently in each,
 * content seeded before Transport was installed, an environment rebuilt from scratch.
 * Importing such an element as new either duplicates it or, when Craft can't produce a
 * unique URI for it, fails the import outright.
 *
 * A handler implementing this contract answers "which element here is already this
 * one?" using the element type's natural key — a single's section, a slug within its
 * section or group, an asset's filename in its folder, a user's email — so the import
 * updates it instead.
 *
 * {@see BaseElementHandler} implements it with a null default, so handlers extending
 * that class opt in simply by overriding {@see matchExisting()}.
 */
interface MatchesExistingElements
{
    /**
     * Returns the element in this environment that the serialized payload describes,
     * ignoring UID, or null when there isn't one.
     *
     * Only ever called after a UID lookup has already come up empty, and never for
     * resolving references between elements — a relation must match by UID or not at
     * all.
     *
     * @param array $data The serialized element (uid, type, key, attributes, sites).
     * @param int|null $siteId Restrict to one site, or null to match in any site.
     */
    public function matchExisting(array $data, ?int $siteId = null): ?ElementInterface;
}
