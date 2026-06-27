<?php

namespace justinholtweb\transport\elements\commerce;

use craft\base\ElementInterface;
use craft\commerce\elements\Variant;
use justinholtweb\transport\elements\BaseElementHandler;
use justinholtweb\transport\helpers\IdentityHelper;
use justinholtweb\transport\Plugin;

/**
 * Element handler for standalone Craft Commerce variants.
 *
 * Variants are normally exported inline with their product (see {@see ProductHandler}).
 * This handler exists for cases where variants are exported directly and to make
 * variant references resolvable.
 *
 * Only registered when craftcms/commerce is installed. Experimental.
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
        $product = $element->getProduct();

        return [
            'product' => $product?->uid,
            'sku' => $element->sku,
            'isDefault' => $element->isDefault,
            'price' => $element->price ?? null,
            'stock' => $element->stock,
            'hasUnlimitedStock' => $element->hasUnlimitedStock,
            'width' => $element->width,
            'height' => $element->height,
            'length' => $element->length,
            'weight' => $element->weight,
        ];
    }

    public function makeElement(array $attributes): ?ElementInterface
    {
        $productUid = $attributes['product'] ?? null;
        $productId = $productUid
            ? IdentityHelper::resolveId($productUid, \craft\commerce\elements\Product::class)
            : null;

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
        foreach (['isDefault', 'price', 'stock', 'hasUnlimitedStock', 'width', 'height', 'length', 'weight'] as $attr) {
            if (array_key_exists($attr, $attributes)) {
                $element->$attr = $attributes[$attr];
            }
        }
    }

    public function collectReferences(ElementInterface $element): array
    {
        /** @var Variant $element */
        return $element->getProduct() ? [$element->getProduct()->uid] : [];
    }
}
