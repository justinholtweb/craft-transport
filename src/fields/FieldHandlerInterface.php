<?php

namespace justinholtweb\transport\fields;

use craft\base\ElementInterface;
use craft\base\FieldInterface;

/**
 * Contract for converting a single custom field's value to and from Transport's
 * portable representation.
 *
 * Portable values must never contain environment-specific element IDs — use UIDs,
 * handles, or other stable references instead.
 */
interface FieldHandlerInterface
{
    /**
     * Whether this handler can serialize/normalize the given field.
     */
    public function canHandle(FieldInterface $field): bool;

    /**
     * Converts the field's current value on $element into a portable, JSON-safe value.
     */
    public function serialize(ElementInterface $element, FieldInterface $field): mixed;

    /**
     * Converts a portable value back into a value that can be assigned to $field on
     * $element via {@see ElementInterface::setFieldValue()}.
     */
    public function normalize(mixed $data, FieldInterface $field, ?ElementInterface $element): mixed;

    /**
     * Returns the UID references this field's value depends on, so the dependency
     * resolver can order imports correctly.
     *
     * @return string[] List of element UIDs referenced by the field value.
     */
    public function collectReferences(ElementInterface $element, FieldInterface $field): array;
}
