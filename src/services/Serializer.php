<?php

namespace justinholtweb\transport\services;

use Craft;
use craft\base\ElementInterface;
use justinholtweb\transport\Plugin;
use yii\base\Component;

/**
 * Converts elements into Transport's portable, UID-based array representation.
 *
 * The serializer owns the common envelope and per-site content, delegating
 * type-specific (site-independent) attributes to element handlers and per-field
 * values to field handlers.
 *
 * Format (v2):
 *   uid, type, key, enabled (global), attributes {…shared…},
 *   sites: { <siteHandle>: { title, slug, enabled, fields {…} } }
 */
class Serializer extends Component
{
    /**
     * Serializes a single element — across every site it supports — into a portable array.
     */
    public function serializeElement(ElementInterface $element): array
    {
        $handler = Plugin::getInstance()->elementRegistry->getHandlerForType($element::class);

        return [
            'uid' => $element->uid,
            'type' => $element::class,
            'key' => $handler?->packageKey() ?? 'elements',
            'enabled' => $element->enabled,
            'attributes' => $handler?->serializeAttributes($element) ?? [],
            'sites' => $this->serializeSites($element),
        ];
    }

    /**
     * Serializes per-site content for every site the element supports.
     *
     * @return array<string, array>
     */
    public function serializeSites(ElementInterface $element): array
    {
        $sites = [];

        foreach ($this->supportedSiteElements($element) as $handle => $siteElement) {
            $sites[$handle] = [
                'title' => $siteElement->title,
                'slug' => $siteElement->slug ?? null,
                'enabled' => $siteElement->getEnabledForSite() ?? true,
                'fields' => $this->serializeFieldValues($siteElement),
            ];
        }

        return $sites;
    }

    /**
     * Serializes every custom field value on an element through the field registry.
     *
     * @return array<string, mixed> Keyed by field handle.
     */
    public function serializeFieldValues(ElementInterface $element): array
    {
        $values = [];
        $layout = $element->getFieldLayout();

        if (!$layout) {
            return $values;
        }

        foreach ($layout->getCustomFields() as $field) {
            $handler = Plugin::getInstance()->fieldRegistry->getHandler($field);
            $values[$field->handle] = $handler->serialize($element, $field);
        }

        return $values;
    }

    /**
     * Collects all UID references an element makes — across its attributes and its
     * custom field values in every site — for dependency resolution.
     *
     * @return string[]
     */
    public function collectReferences(ElementInterface $element): array
    {
        $handler = Plugin::getInstance()->elementRegistry->getHandlerForType($element::class);
        $refs = $handler?->collectReferences($element) ?? [];

        foreach ($this->supportedSiteElements($element) as $siteElement) {
            $refs = array_merge($refs, $this->collectFieldReferences($siteElement));
        }

        return array_values(array_unique($refs));
    }

    /**
     * @return string[]
     */
    public function collectFieldReferences(ElementInterface $element): array
    {
        $refs = [];
        $layout = $element->getFieldLayout();

        if (!$layout) {
            return $refs;
        }

        foreach ($layout->getCustomFields() as $field) {
            $handler = Plugin::getInstance()->fieldRegistry->getHandler($field);
            foreach ($handler->collectReferences($element, $field) as $uid) {
                $refs[] = $uid;
            }
        }

        return $refs;
    }

    /**
     * Loads the element in each site it supports, keyed by site handle.
     *
     * @return array<string, ElementInterface>
     */
    private function supportedSiteElements(ElementInterface $element): array
    {
        $result = [];

        foreach ($element->getSupportedSites() as $supportedSite) {
            $siteId = is_array($supportedSite) ? $supportedSite['siteId'] : $supportedSite;

            $siteElement = $element::find()
                ->id($element->id)
                ->siteId($siteId)
                ->status(null)
                ->drafts(null)
                ->revisions(null)
                ->one();

            if ($siteElement === null) {
                continue;
            }

            $handle = Craft::$app->getSites()->getSiteById($siteId)?->handle;
            if ($handle !== null) {
                $result[$handle] = $siteElement;
            }
        }

        // Fall back to the element as given if it reports no supported sites.
        if (!$result) {
            $result[$element->getSite()->handle] = $element;
        }

        return $result;
    }
}
