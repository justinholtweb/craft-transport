<?php

namespace justinholtweb\transport\elements;

use craft\base\ElementInterface;

/**
 * Convenience base class for element handlers. Subclasses must declare the element
 * type, its package key, and how to (de)serialize type-specific attributes.
 */
abstract class BaseElementHandler implements ElementHandlerInterface
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
}
