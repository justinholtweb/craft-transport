<?php

namespace justinholtweb\transport\fields\thirdparty;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use justinholtweb\transport\fields\FieldHandlerInterface;
use justinholtweb\transport\helpers\IdentityHelper;
use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;

/**
 * Handles Verbb Hyper fields. Hyper stores element links (entry/category/asset/user/
 * product/variant) as a local element id in each link's `linkValue`; this handler
 * rewrites those ids to portable UID references and back.
 *
 * Only registered when verbb/hyper is installed.
 */
class HyperFieldHandler implements FieldHandlerInterface
{
    private const UID_KEY = '_transportUid';
    private const TYPE_KEY = '_transportType';

    public function canHandle(FieldInterface $field): bool
    {
        return $field instanceof HyperField;
    }

    public function serialize(ElementInterface $element, FieldInterface $field): mixed
    {
        $serialized = $field->serializeValue($element->getFieldValue($field->handle), $element);
        if (!is_array($serialized)) {
            return $serialized;
        }

        foreach ($serialized as &$link) {
            $ref = $this->elementRef($link);
            if ($ref !== null) {
                $link['linkValue'] = $ref;
            }
        }

        return $serialized;
    }

    public function normalize(mixed $data, FieldInterface $field, ?ElementInterface $element): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        foreach ($data as &$link) {
            if (isset($link['linkValue']) && is_array($link['linkValue']) && isset($link['linkValue'][self::UID_KEY])) {
                $id = IdentityHelper::resolveId(
                    $link['linkValue'][self::UID_KEY],
                    $link['linkValue'][self::TYPE_KEY]
                );
                // Hyper stores element links as an array of ids.
                $link['linkValue'] = $id !== null ? [$id] : null;
            }
        }

        return $field->normalizeValue($data, $element);
    }

    public function collectReferences(ElementInterface $element, FieldInterface $field): array
    {
        $refs = [];
        $serialized = $field->serializeValue($element->getFieldValue($field->handle), $element);
        if (!is_array($serialized)) {
            return $refs;
        }

        foreach ($serialized as $link) {
            $ref = $this->elementRef($link);
            if ($ref !== null) {
                $refs[] = $ref[self::UID_KEY];
            }
        }

        return $refs;
    }

    /**
     * Returns a portable UID reference for an element link, or null for non-element
     * links (URL, email, phone, custom).
     */
    private function elementRef(array $link): ?array
    {
        $type = $link['type'] ?? null;
        $rawValue = $link['linkValue'] ?? null;

        if (!$type || $rawValue === null || $rawValue === [] || !is_subclass_of($type, ElementLink::class)) {
            return null;
        }

        // Element links store their target as an array of ids (typically one).
        $id = is_array($rawValue) ? ($rawValue[0] ?? null) : $rawValue;
        if (!$id) {
            return null;
        }

        /** @var class-string<ElementInterface> $elementType */
        $elementType = $type::elementType();
        $linked = Craft::$app->getElements()->getElementById((int)$id, $elementType, null, ['status' => null]);

        return $linked
            ? [self::UID_KEY => $linked->uid, self::TYPE_KEY => $elementType]
            : null;
    }
}
