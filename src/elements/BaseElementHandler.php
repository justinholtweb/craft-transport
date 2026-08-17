<?php

namespace justinholtweb\transport\elements;

use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;

/**
 * Convenience base class for element handlers. Subclasses must declare the element
 * type, its package key, and how to (de)serialize type-specific attributes.
 */
abstract class BaseElementHandler implements ElementHandlerInterface, MatchesExistingElements
{
    public function serializeAttributes(ElementInterface $element): array
    {
        return [];
    }

    public function applyAttributes(array $attributes, ElementInterface $element): void
    {
    }

    public function collectReferences(ElementInterface $element): array
    {
        return [];
    }

    /**
     * No natural key by default — handlers that have one override this.
     */
    public function matchExisting(array $data, ?int $siteId = null): ?ElementInterface
    {
        return null;
    }

    /**
     * Scopes a query to one site, or to a single result across all of them.
     */
    protected function scopeToSite(ElementQueryInterface $query, ?int $siteId): ElementQueryInterface
    {
        return $siteId !== null
            ? $query->siteId($siteId)
            : $query->site('*')->unique();
    }

    /**
     * Every slug the payload carries, across its sites.
     *
     * Matching against all of them (rather than one site's) keeps the lookup working
     * when the source and target sites are named differently, or when only some sites
     * came across in the package.
     *
     * @return string[]
     */
    protected function slugsFrom(array $data): array
    {
        $slugs = [];

        foreach ((array)($data['sites'] ?? []) as $site) {
            if (!empty($site['slug'])) {
                $slugs[] = (string)$site['slug'];
            }
        }

        return array_values(array_unique($slugs));
    }
}
