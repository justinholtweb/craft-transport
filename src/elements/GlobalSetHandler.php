<?php

namespace justinholtweb\transport\elements;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\GlobalSet;

/**
 * Element handler for global sets.
 *
 * Global sets themselves are defined in project config — Transport only moves their
 * content. On import we never create a set; we resolve the existing one by handle and
 * apply field values to it.
 */
class GlobalSetHandler extends BaseElementHandler
{
    public function elementType(): string
    {
        return GlobalSet::class;
    }

    public function packageKey(): string
    {
        return 'globals';
    }

    public function query(): ElementQueryInterface
    {
        return GlobalSet::find()->status(null);
    }

    public function serializeAttributes(ElementInterface $element): array
    {
        /** @var GlobalSet $element */
        return [
            'handle' => $element->handle,
            'name' => $element->name,
        ];
    }

    public function makeElement(array $attributes): ?ElementInterface
    {
        // Resolve the existing set by handle; we update content, never create the set.
        $handle = $attributes['handle'] ?? null;
        if (!$handle) {
            return null;
        }

        return Craft::$app->getGlobals()->getSetByHandle($handle);
    }

    /**
     * Global sets are always matched by handle — they're defined in project config, so
     * their UIDs differ between environments as a matter of course.
     */
    public function matchExisting(array $data, ?int $siteId = null): ?ElementInterface
    {
        $handle = $data['attributes']['handle'] ?? null;

        return $handle ? Craft::$app->getGlobals()->getSetByHandle($handle) : null;
    }
}
