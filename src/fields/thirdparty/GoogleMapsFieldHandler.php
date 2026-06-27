<?php

namespace justinholtweb\transport\fields\thirdparty;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use justinholtweb\transport\fields\FieldHandlerInterface;

/**
 * Handles the Google Maps (doublesecretagency) Address field.
 *
 * The address data (lat/lng/parts) is self-contained, but the serialized value also
 * carries the owner's `elementId`/`siteId`/`fieldId`. Those are environment-specific, so
 * this handler strips them; the field repopulates them from the target element on import.
 *
 * Only registered when doublesecretagency/craft-googlemaps is installed.
 */
class GoogleMapsFieldHandler implements FieldHandlerInterface
{
    public function canHandle(FieldInterface $field): bool
    {
        return $field instanceof \doublesecretagency\googlemaps\fields\AddressField;
    }

    public function serialize(ElementInterface $element, FieldInterface $field): mixed
    {
        $serialized = $field->serializeValue($element->getFieldValue($field->handle), $element);
        if (is_array($serialized)) {
            // Drop environment-specific keys; the field repopulates them on import.
            unset($serialized['id'], $serialized['elementId'], $serialized['siteId'], $serialized['fieldId']);
        }
        return $serialized;
    }

    public function normalize(mixed $data, FieldInterface $field, ?ElementInterface $element): mixed
    {
        // normalizeValue fills elementId/siteId/fieldId from the target element/field.
        return $field->normalizeValue($data, $element);
    }

    public function collectReferences(ElementInterface $element, FieldInterface $field): array
    {
        return [];
    }
}
