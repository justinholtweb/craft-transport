<?php

namespace justinholtweb\transport\elements\commerce;

use craft\base\ElementInterface;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use justinholtweb\transport\elements\BaseElementHandler;
use justinholtweb\transport\helpers\IdentityHelper;

/**
 * Element handler for standalone Craft Commerce 5 variants.
 *
 * Variants are normally exported inline with their product (see {@see ProductHandler}).
 * This handler covers direct variant export and makes variant references resolvable.
 * Only registered when Commerce is installed.
 */
class VariantHandler extends BaseElementHandler
{
    public function elementType(): string
    {
        return Variant::class;
    }

    public function packageKey(): string
    {
        return 'variants';
    }

    public function query(): \craft\elements\db\ElementQueryInterface
    {
        return Variant::find()->status(null);
    }

    public function serializeAttributes(ElementInterface $element): array
    {
        /** @var Variant $element */
        return [
            'product' => $element->getProduct()?->uid,
            'sku' => $element->getSku(),
            'isDefault' => $element->isDefault,
            'basePrice' => $element->getBasePrice(),
            'width' => $element->width,
            'height' => $element->height,
            'length' => $element->length,
            'weight' => $element->weight,
        ];
    }

    public function makeElement(array $attributes): ?ElementInterface
    {
        $productUid = $attributes['product'] ?? null;
        $productId = $productUid ? IdentityHelper::resolveId($productUid, Product::class) : null;
        if ($productId === null) {
            return null;
        }

        $variant = new Variant();
        $variant->setPrimaryOwnerId($productId);

        return $variant;
    }

    public function applyAttributes(array $attributes, ElementInterface $element): void
    {
        /** @var Variant $element */
        if (isset($attributes['sku'])) {
            $element->setSku($attributes['sku']);
        }
        if (array_key_exists('basePrice', $attributes) && $attributes['basePrice'] !== null) {
            $element->setBasePrice((float)$attributes['basePrice']);
        }
        $element->isDefault = (bool)($attributes['isDefault'] ?? $element->isDefault);
        foreach (['width', 'height', 'length', 'weight'] as $dim) {
            if (array_key_exists($dim, $attributes)) {
                $element->$dim = $attributes[$dim];
            }
        }
    }

    public function collectReferences(ElementInterface $element): array
    {
        /** @var Variant $element */
        return $element->getProduct() ? [$element->getProduct()->uid] : [];
    }
}
