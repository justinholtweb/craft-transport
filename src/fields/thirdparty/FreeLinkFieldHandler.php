<?php

namespace justinholtweb\transport\fields\thirdparty;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use justinholtweb\freelink\base\ElementLink;
use justinholtweb\freelink\fields\FreeLinkField;
use justinholtweb\transport\fields\FieldHandlerInterface;
use justinholtweb\transport\helpers\IdentityHelper;

/**
 * Handles justinholtweb Freelink fields.
 *
 * Freelink stores element-link targets in its own relations table, not in the content
 * column — `toArray()` deliberately nulls the value. So this handler reads the live
 * link objects (whose `targetId` is loaded from relations), serializes a portable UID
 * reference, and feeds a resolved `targetId` back on import so Freelink re-creates the
 * relation in `afterElementSave()`.
 *
 * Only registered when justinholtweb/craft-freelink is installed.
 */
class FreeLinkFieldHandler implements FieldHandlerInterface
{
    private const UID_KEY = '_transportUid';
    private const TYPE_KEY = '_transportType';

    public function canHandle(FieldInterface $field): bool
    {
        return $field instanceof FreeLinkField;
    }

    public function serialize(ElementInterface $element, FieldInterface $field): mixed
    {
        $links = $this->links($element, $field);
        if (!$links) {
            return null;
        }

        $out = [];
        foreach ($links as $link) {
            $data = $link->toArray();
            if ($link instanceof ElementLink && $link->targetId) {
                $linked = Craft::$app->getElements()->getElementById((int)$link->targetId, null, null, ['status' => null]);
                if ($linked) {
                    $data['targetId'] = [self::UID_KEY => $linked->uid, self::TYPE_KEY => $linked::class];
                }
            }
            $out[] = $data;
        }

        // Single-link fields serialize as a lone object.
        return (!$field->multipleLinks && count($out) === 1) ? $out[0] : $out;
    }

    public function normalize(mixed $data, FieldInterface $field, ?ElementInterface $element): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $single = isset($data['type']);
        $links = $single ? [$data] : $data;

        foreach ($links as &$link) {
            if (is_array($link) && isset($link['targetId']) && is_array($link['targetId']) && isset($link['targetId'][self::UID_KEY])) {
                $link['targetId'] = IdentityHelper::resolveId(
                    $link['targetId'][self::UID_KEY],
                    $link['targetId'][self::TYPE_KEY]
                );
            }
        }
        unset($link);

        return $field->normalizeValue($single ? $links[0] : $links, $element);
    }

    public function collectReferences(ElementInterface $element, FieldInterface $field): array
    {
        $refs = [];
        foreach ($this->links($element, $field) as $link) {
            if ($link instanceof ElementLink && $link->targetId) {
                $linked = Craft::$app->getElements()->getElementById((int)$link->targetId, null, null, ['status' => null]);
                if ($linked) {
                    $refs[] = $linked->uid;
                }
            }
        }
        return $refs;
    }

    /**
     * Returns the live Link objects from the field's LinkCollection.
     *
     * @return object[]
     */
    private function links(ElementInterface $element, FieldInterface $field): array
    {
        $value = $element->getFieldValue($field->handle);
        if (is_object($value) && method_exists($value, 'getAll')) {
            return $value->getAll();
        }
        return [];
    }
}
