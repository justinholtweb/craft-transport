<?php

namespace justinholtweb\transport\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\fields\BaseRelationField;
use justinholtweb\transport\helpers\IdentityHelper;

/**
 * Handles relation fields (Entries, Categories, Tags, Assets, Users).
 *
 * Serializes related elements as ordered UID references instead of environment-local
 * IDs, and resolves them back to local IDs on import.
 */
class RelationFieldHandler implements FieldHandlerInterface
{
    public function canHandle(FieldInterface $field): bool
    {
        return $field instanceof BaseRelationField;
    }

    public function serialize(ElementInterface $element, FieldInterface $field): mixed
    {
        $refs = [];

        foreach ($this->relatedIds($element, $field) as $id) {
            $related = Craft::$app->getElements()->getElementById(
                (int)$id,
                null,
                $element->siteId,
                ['status' => null]
            );

            if ($related) {
                $refs[] = [
                    'uid' => $related->uid,
                    'type' => $related::class,
                ];
            }
        }

        return $refs;
    }

    public function normalize(mixed $data, FieldInterface $field, ?ElementInterface $element): mixed
    {
        if (!is_array($data)) {
            return [];
        }

        $ids = [];
        foreach ($data as $ref) {
            if (!isset($ref['uid'], $ref['type'])) {
                continue;
            }
            $localId = IdentityHelper::resolveId($ref['uid'], $ref['type']);
            if ($localId !== null) {
                $ids[] = $localId;
            }
        }

        return $ids;
    }

    public function collectReferences(ElementInterface $element, FieldInterface $field): array
    {
        return array_values(array_filter(array_map(
            static fn(array $ref): ?string => $ref['uid'] ?? null,
            $this->serialize($element, $field)
        )));
    }

    /**
     * Returns the ordered list of related element IDs for the field.
     *
     * @return int[]
     */
    private function relatedIds(ElementInterface $element, FieldInterface $field): array
    {
        $value = $element->getFieldValue($field->handle);

        // Eager-loaded relations return a collection; lazy ones an element query.
        if (is_iterable($value) && !is_array($value)) {
            $ids = [];
            foreach ($value as $related) {
                $ids[] = $related->id;
            }
            return $ids;
        }

        $serialized = $field->serializeValue($value, $element);
        return is_array($serialized) ? array_map('intval', $serialized) : [];
    }
}
