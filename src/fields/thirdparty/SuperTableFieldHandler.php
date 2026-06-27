<?php

namespace justinholtweb\transport\fields\thirdparty;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use justinholtweb\transport\fields\FieldHandlerInterface;
use justinholtweb\transport\Plugin;

/**
 * Handles Verbb Super Table fields. Super Table values are nested blocks; this handler
 * serializes them recursively through the field registry.
 *
 * Only registered when verbb/super-table is installed. Experimental — not yet exercised
 * against a live Super Table install.
 */
class SuperTableFieldHandler implements FieldHandlerInterface
{
    public function canHandle(FieldInterface $field): bool
    {
        return $field instanceof \verbb\supertable\fields\SuperTableField;
    }

    public function serialize(ElementInterface $element, FieldInterface $field): mixed
    {
        $blocks = [];
        foreach ($this->blocks($element, $field) as $block) {
            $blocks[] = [
                'uid' => $block->uid,
                'type' => method_exists($block, 'getType') ? $block->getType()?->handle : null,
                'enabled' => $block->enabled,
                'fields' => Plugin::getInstance()->serializer->serializeFieldValues($block),
            ];
        }
        return $blocks;
    }

    public function normalize(mixed $data, FieldInterface $field, ?ElementInterface $element): mixed
    {
        if (!is_array($data)) {
            return null;
        }

        $sortOrder = [];
        $blocks = [];
        foreach ($data as $i => $block) {
            $key = 'new' . ($i + 1);
            $sortOrder[] = $key;
            $blocks[$key] = [
                'type' => $block['type'] ?? null,
                'enabled' => $block['enabled'] ?? true,
                'fields' => $block['fields'] ?? [],
            ];
        }

        return ['sortOrder' => $sortOrder, 'blocks' => $blocks];
    }

    public function collectReferences(ElementInterface $element, FieldInterface $field): array
    {
        $refs = [];
        foreach ($this->blocks($element, $field) as $block) {
            foreach (Plugin::getInstance()->serializer->collectFieldReferences($block) as $uid) {
                $refs[] = $uid;
            }
        }
        return $refs;
    }

    /**
     * @return ElementInterface[]
     */
    private function blocks(ElementInterface $element, FieldInterface $field): array
    {
        $value = $element->getFieldValue($field->handle);
        if (!is_iterable($value)) {
            return [];
        }

        $blocks = [];
        foreach ($value as $block) {
            $blocks[] = $block;
        }
        return $blocks;
    }
}
