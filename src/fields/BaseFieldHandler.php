<?php

namespace justinholtweb\transport\fields;

use craft\base\ElementInterface;
use craft\base\FieldInterface;

/**
 * Generic fallback field handler.
 *
 * Delegates to Craft's own value (de)serialization, which is correct for scalar and
 * self-contained field types (plain text, number, dropdown, date, color, money, etc.).
 * Field types that embed element IDs — relations, Matrix — have dedicated subclasses.
 */
class BaseFieldHandler implements FieldHandlerInterface
{
    public function canHandle(FieldInterface $field): bool
    {
        // The base handler is the universal fallback; the registry only reaches it
        // after every more-specific handler has declined.
        return true;
    }

    public function serialize(ElementInterface $element, FieldInterface $field): mixed
    {
        $value = $element->getFieldValue($field->handle);
        return $field->serializeValue($value, $element);
    }

    public function normalize(mixed $data, FieldInterface $field, ?ElementInterface $element): mixed
    {
        return $field->normalizeValue($data, $element);
    }

    public function collectReferences(ElementInterface $element, FieldInterface $field): array
    {
        return [];
    }
}
