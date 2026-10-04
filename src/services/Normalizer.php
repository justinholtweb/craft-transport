<?php

namespace justinholtweb\transport\services;

use Craft;
use craft\base\ElementInterface;
use craft\models\FieldLayout;
use justinholtweb\transport\elements\ElementHandlerInterface;
use justinholtweb\transport\helpers\IdentityHelper;
use justinholtweb\transport\Plugin;
use yii\base\Component;

/**
 * Reconstructs elements from Transport's portable representation, resolving UID
 * references back to local IDs.
 *
 * The normalizer returns unsaved elements scoped to a single target site; the
 * {@see Import} service drives the per-site save loop (within a transaction, with
 * snapshotting) so a multi-site element is created in its primary site and then
 * has each additional site's content applied.
 */
class Normalizer extends Component
{
    /**
     * Builds (or loads, for updates) an element for one target site and applies its
     * shared attributes plus that site's content. Returns null when the element can't
     * be hosted (missing section/group/volume, or the target site doesn't exist).
     *
     * @param array $data Serialized element payload (multi-site format).
     * @param string $sourceSiteHandle Site key within $data['sites'] to apply.
     * @param array<string, string> $siteMap Optional source→target site handle mapping.
     * @param int|null $elementId The element the caller has already identified for this
     *                            payload. Passing it keeps every site of a multi-site
     *                            element on that one element instead of re-matching (and
     *                            possibly duplicating) it per site.
     */
    public function normalizeElementForSite(
        array $data,
        string $sourceSiteHandle,
        array $siteMap = [],
        ?int $elementId = null,
    ): ?ElementInterface {
        $type = $data['type'] ?? null;
        $uid = $data['uid'] ?? null;
        if (!$type || !$uid) {
            return null;
        }

        $siteData = $data['sites'][$sourceSiteHandle] ?? null;
        if ($siteData === null) {
            return null;
        }

        $targetHandle = $siteMap[$sourceSiteHandle] ?? $sourceSiteHandle;
        $site = Craft::$app->getSites()->getSiteByHandle($targetHandle);
        if ($site === null) {
            return null;
        }

        $handler = Plugin::getInstance()->elementRegistry->getHandlerForType($type);

        if ($elementId !== null) {
            // Already identified: use that element, or skip the site if it isn't part
            // of it — never create a second copy behind the caller's back.
            return $this->applyTo($this->findInSiteById($elementId, $type, $site->id), $data, $siteData, $handler);
        }

        // The UID this package carries, or whatever the handler recognises as the same
        // content already living here under a different one.
        $element = IdentityHelper::resolveImportTarget($data, $site->id);
        if ($element === null) {
            $element = $handler?->makeElement($data['attributes'] ?? []);
            if ($element === null) {
                return null;
            }
            // Only adopt the package UID for genuinely new elements. Handlers that
            // resolve an existing element (e.g. global sets by handle) keep their own.
            if ($element->id === null) {
                $element->uid = $uid;
            }
            $element->siteId = $site->id;
        }

        return $this->applyTo($element, $data, $siteData, $handler);
    }

    /**
     * Applies the payload's shared attributes and one site's content onto an element.
     */
    private function applyTo(
        ?ElementInterface $element,
        array $data,
        array $siteData,
        ?ElementHandlerInterface $handler,
    ): ?ElementInterface {
        if ($element === null) {
            return null;
        }

        if (array_key_exists('enabled', $data)) {
            $element->enabled = (bool)$data['enabled'];
        }
        $element->setEnabledForSite((bool)($siteData['enabled'] ?? true));

        $handler?->applyAttributes($data['attributes'] ?? [], $element);

        if (!empty($siteData['title'])) {
            $element->title = $siteData['title'];
        }
        if (array_key_exists('slug', $siteData) && $siteData['slug'] !== null) {
            $element->slug = $siteData['slug'];
        }

        $this->applyFieldValues($siteData['fields'] ?? [], $element);

        return $element;
    }

    /**
     * Returns the site handles present in a payload, primary site first so creation
     * happens before propagated sites are updated.
     *
     * @return string[]
     */
    public function orderedSiteHandles(array $data): array
    {
        $handles = array_keys($data['sites'] ?? []);
        $primary = Craft::$app->getSites()->getPrimarySite()->handle;

        usort($handles, static function($a, $b) use ($primary) {
            if ($a === $primary) {
                return -1;
            }
            if ($b === $primary) {
                return 1;
            }
            return 0;
        });

        return $handles;
    }

    /**
     * Normalizes a map of serialized field values against a field layout and assigns
     * them to the element.
     */
    public function applyFieldValues(array $fields, ElementInterface $element): void
    {
        $values = $this->normalizeFieldValues($fields, $element->getFieldLayout(), $element);

        foreach ($values as $handle => $value) {
            $element->setFieldValue($handle, $value);
        }
    }

    /**
     * Normalizes serialized field values against a field layout, returning a
     * handle => value map without assigning anything. Used for nested contexts.
     *
     * @return array<string, mixed>
     */
    public function normalizeFieldValues(array $fields, ?FieldLayout $layout, ?ElementInterface $context): array
    {
        if (!$layout) {
            return [];
        }

        $fieldsByHandle = [];
        foreach ($layout->getCustomFields() as $field) {
            $fieldsByHandle[$field->handle] = $field;
        }

        $values = [];
        foreach ($fields as $handle => $data) {
            $field = $fieldsByHandle[$handle] ?? null;
            if ($field === null) {
                Craft::warning("Skipping unknown field \"$handle\" during import.", 'transport');
                continue;
            }

            $handler = Plugin::getInstance()->fieldRegistry->getHandler($field);
            $values[$handle] = $handler->normalize($data, $field, $context);
        }

        return $values;
    }

    /**
     * Loads an already-identified element in one site, or null when it doesn't live
     * there (a section or group that doesn't cover the site, or content Craft hasn't
     * propagated to it).
     *
     * @param class-string<ElementInterface> $elementType
     */
    private function findInSiteById(int $id, string $elementType, int $siteId): ?ElementInterface
    {
        return $elementType::find()
            ->id($id)
            ->siteId($siteId)
            ->status(null)
            ->drafts(null)
            ->revisions(null)
            ->one();
    }
}
