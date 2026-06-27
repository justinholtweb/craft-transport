<?php

namespace justinholtweb\transport\elements\commerce;

use Craft;
use craft\base\ElementInterface;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use justinholtweb\transport\elements\BaseElementHandler;
use justinholtweb\transport\Plugin;

/**
 * Element handler for Craft Commerce products. Variants are serialized inline.
 *
 * Only registered when craftcms/commerce is installed. Experimental — not yet exercised
 * against a live Commerce install.
 */
class ProductHandler extends BaseElementHandler
{
    public function elementType(): string
    {
        return Product::class;
    }

    public function packageKey(): string
    {
        return 'products';
    }

    public function query(): \craft\elements\db\ElementQueryInterface
    {
        return Product::find()->status(null);
    }

    public function serializeAttributes(ElementInterface $element): array
    {
        /** @var Product $element */
        $commerce = Commerce::getInstance();

        $taxCategory = $element->taxCategoryId
            ? ($commerce->getTaxCategories()->getTaxCategoryById($element->taxCategoryId)?->handle)
            : null;
        $shippingCategory = $element->shippingCategoryId
            ? ($commerce->getShippingCategories()->getShippingCategoryById($element->shippingCategoryId)?->handle)
            : null;

        return [
            'type' => $element->getType()->handle,
            'taxCategory' => $taxCategory,
            'shippingCategory' => $shippingCategory,
            'variants' => array_map(
                fn(Variant $variant) => $this->serializeVariant($variant),
                $element->getVariants(true)
            ),
        ];
    }

    public function makeElement(array $attributes): ?ElementInterface
    {
        $type = isset($attributes['type'])
            ? Commerce::getInstance()->getProductTypes()->getProductTypeByHandle($attributes['type'])
            : null;

        if (!$type) {
            return null;
        }

        $product = new Product();
        $product->typeId = $type->id;

        return $product;
    }

    public function applyAttributes(array $attributes, ElementInterface $element): void
    {
        /** @var Product $element */
        $commerce = Commerce::getInstance();

        if (!empty($attributes['taxCategory'])) {
            $element->taxCategoryId = $commerce->getTaxCategories()->getTaxCategoryByHandle($attributes['taxCategory'])?->id;
        }
        if (!empty($attributes['shippingCategory'])) {
            $element->shippingCategoryId = $commerce->getShippingCategories()->getShippingCategoryByHandle($attributes['shippingCategory'])?->id;
        }

        $variants = [];
        foreach ($attributes['variants'] ?? [] as $i => $variantData) {
            $variants['new' . ($i + 1)] = $this->normalizeVariant($variantData);
        }
        if ($variants) {
            $element->setVariants($variants);
        }
    }

    private function serializeVariant(Variant $variant): array
    {
        return [
            'sku' => $variant->sku,
            'isDefault' => $variant->isDefault,
            'price' => $variant->price ?? null,
            'stock' => $variant->stock,
            'hasUnlimitedStock' => $variant->hasUnlimitedStock,
            'width' => $variant->width,
            'height' => $variant->height,
            'length' => $variant->length,
            'weight' => $variant->weight,
            'title' => $variant->title,
            'fields' => Plugin::getInstance()->serializer->serializeFieldValues($variant),
        ];
    }

    private function normalizeVariant(array $data): array
    {
        // Build the array shape Commerce expects for a posted/new variant.
        return [
            'sku' => $data['sku'] ?? null,
            'isDefault' => $data['isDefault'] ?? false,
            'price' => $data['price'] ?? 0,
            'stock' => $data['stock'] ?? null,
            'hasUnlimitedStock' => $data['hasUnlimitedStock'] ?? false,
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
            'length' => $data['length'] ?? null,
            'weight' => $data['weight'] ?? null,
            'title' => $data['title'] ?? null,
            'fields' => $data['fields'] ?? [],
        ];
    }

    public function collectReferences(ElementInterface $element): array
    {
        /** @var Product $element */
        $refs = [];
        foreach ($element->getVariants(true) as $variant) {
            foreach (Plugin::getInstance()->serializer->collectFieldReferences($variant) as $uid) {
                $refs[] = $uid;
            }
        }
        return $refs;
    }
}
