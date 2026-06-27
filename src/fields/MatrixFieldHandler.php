<?php

namespace justinholtweb\transport\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Matrix;
use justinholtweb\transport\Plugin;

/**
 * Handles Matrix fields, whose values are nested entries (Craft 5).
 *
 * Each nested entry is serialized recursively through the field registry so its own
 * custom fields — including further nested Matrix/relation fields — are portable.
 */
class MatrixFieldHandler implements FieldHandlerInterface
{
    public function canHandle(FieldInterface $field): bool
    {
        return $field instanceof Matrix;
    }

    public function serialize(ElementInterface $element, FieldInterface $field): mixed
    {
        $blocks = [];

        foreach ($this->nestedEntries($element, $field) as $entry) {
            $blocks[] = [
                'uid' => $entry->uid,
                'type' => $entry->getType()->handle,
                'enabled' => $entry->enabled,
                'fields' => Plugin::getInstance()->serializer->serializeFieldValues($entry),
            ];
        }

        return $blocks;
    }

    public function normalize(mixed $data, FieldInterface $field, ?ElementInterface $element): mixed
    {
        if (!is_array($data)) {
            return null;
        }

        // Build Craft's expected nested-entry input format: a sortOrder list plus a
        // map of block definitions keyed by a temporary client-side id.
        $sortOrder = [];
        $entries = [];

        foreach ($data as $i => $block) {
            $key = 'new' . ($i + 1);
            $sortOrder[] = $key;
            $layout = $this->entryTypeLayout($field, $block['type'] ?? null);
            $entries[$key] = [
                'type' => $block['type'] ?? null,
                'enabled' => $block['enabled'] ?? true,
                'fields' => Plugin::getInstance()->normalizer->normalizeFieldValues(
                    $block['fields'] ?? [],
                    $layout,
                    $element
                ),
            ];
        }

        return [
            'sortOrder' => $sortOrder,
            'entries' => $entries,
        ];
    }

    public function collectReferences(ElementInterface $element, FieldInterface $field): array
    {
        $refs = [];

        foreach ($this->nestedEntries($element, $field) as $entry) {
            foreach (Plugin::getInstance()->serializer->collectFieldReferences($entry) as $uid) {
                $refs[] = $uid;
            }
        }

        return $refs;
    }

    /**
     * Resolves the field layout for a nested entry type by handle.
     */
    private function entryTypeLayout(Matrix $field, ?string $typeHandle): ?\craft\models\FieldLayout
    {
        if ($typeHandle === null) {
            return null;
        }

        foreach ($field->getEntryTypes() as $entryType) {
            if ($entryType->handle === $typeHandle) {
                return $entryType->getFieldLayout();
            }
        }

        return null;
    }

    /**
     * @return Entry[]
     */
    private function nestedEntries(ElementInterface $element, FieldInterface $field): array
    {
        $value = $element->getFieldValue($field->handle);

        if (is_iterable($value)) {
            $entries = [];
            foreach ($value as $entry) {
                if ($entry instanceof Entry) {
                    $entries[] = $entry;
                }
            }
            return $entries;
        }

        return [];
    }
}
