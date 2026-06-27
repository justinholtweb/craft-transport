<?php

namespace justinholtweb\transport\fields\thirdparty;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Asset;
use justinholtweb\transport\fields\FieldHandlerInterface;
use justinholtweb\transport\helpers\IdentityHelper;

/**
 * Handles the SEOMatic SeoSettings field.
 *
 * Per-element SEO overrides may reference asset ids for the SEO/OG/Twitter images
 * (`seoImageIds`, `ogImageIds`, `twitterImageIds` inside `metaBundleSettings`). This
 * handler rewrites those asset ids to portable UID references and back.
 *
 * Only registered when nystudio107/craft-seomatic is installed.
 */
class SeoSettingsFieldHandler implements FieldHandlerInterface
{
    private const IMAGE_KEYS = ['seoImageIds', 'ogImageIds', 'twitterImageIds'];

    public function canHandle(FieldInterface $field): bool
    {
        return $field instanceof \nystudio107\seomatic\fields\SeoSettings;
    }

    public function serialize(ElementInterface $element, FieldInterface $field): mixed
    {
        $serialized = $field->serializeValue($element->getFieldValue($field->handle), $element);
        if (!is_array($serialized) || empty($serialized['metaBundleSettings'])) {
            return $serialized;
        }

        foreach (self::IMAGE_KEYS as $key) {
            $ids = $serialized['metaBundleSettings'][$key] ?? null;
            if (is_array($ids)) {
                $serialized['metaBundleSettings'][$key] = array_map([$this, 'idToRef'], $ids);
            }
        }

        return $serialized;
    }

    public function normalize(mixed $data, FieldInterface $field, ?ElementInterface $element): mixed
    {
        if (is_array($data) && !empty($data['metaBundleSettings'])) {
            foreach (self::IMAGE_KEYS as $key) {
                $refs = $data['metaBundleSettings'][$key] ?? null;
                if (is_array($refs)) {
                    $data['metaBundleSettings'][$key] = array_values(array_filter(
                        array_map([$this, 'refToId'], $refs),
                        static fn($id) => $id !== null
                    ));
                }
            }
        }

        return $field->normalizeValue($data, $element);
    }

    public function collectReferences(ElementInterface $element, FieldInterface $field): array
    {
        $serialized = $field->serializeValue($element->getFieldValue($field->handle), $element);
        $refs = [];
        if (is_array($serialized) && !empty($serialized['metaBundleSettings'])) {
            foreach (self::IMAGE_KEYS as $key) {
                foreach ((array)($serialized['metaBundleSettings'][$key] ?? []) as $id) {
                    $asset = Asset::find()->id((int)$id)->status(null)->one();
                    if ($asset) {
                        $refs[] = $asset->uid;
                    }
                }
            }
        }
        return $refs;
    }

    private function idToRef(mixed $id): mixed
    {
        $asset = Asset::find()->id((int)$id)->status(null)->one();
        return $asset ? ['_transportUid' => $asset->uid] : $id;
    }

    private function refToId(mixed $ref): ?int
    {
        if (is_array($ref) && isset($ref['_transportUid'])) {
            return IdentityHelper::resolveId($ref['_transportUid'], Asset::class);
        }
        return is_numeric($ref) ? (int)$ref : null;
    }
}
