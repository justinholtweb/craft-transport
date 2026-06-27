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
 * Element handler for Craft Commerce 5 products. Variants are serialized inline.
 *
 * Migrates the product type, tax/shipping categories, and each variant's SKU, base
 * price, dimensions, default flag, and custom fields. Inventory levels and catalog
 * pricing rules are out of scope. Only registered when Commerce is installed.
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
        return [
            'type' => $element->getType()->handle,
            'variants' => array_map(
                fn(Variant $variant) => $this->serializeVariant($variant),
                $element->getVariants(true)->all()
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
        $variantLayout = $element->getType()->getVariantFieldLayout();
        $variants = [];
        foreach ($attributes['variants'] ?? [] as $data) {
            $variants[] = $this->buildVariant($data, $variantLayout, $element);
        }
        if ($variants) {
            $element->setVariants($variants);
        }
    }

    private function serializeVariant(Variant $variant): array
    {
        $commerce = Commerce::getInstance();

        return [
            'sku' => $variant->getSku(),
            'isDefault' => $variant->isDefault,
            'basePrice' => $variant->getBasePrice(),
            'width' => $variant->width,
            'height' => $variant->height,
            'length' => $variant->length,
            'weight' => $variant->weight,
            'title' => $variant->title,
            'taxCategory' => $commerce->getTaxCategories()->getTaxCategoryById($variant->getTaxCategoryId())?->handle,
            'shippingCategory' => $commerce->getShippingCategories()->getShippingCategoryById($variant->getShippingCategoryId())?->handle,
            'fields' => Plugin::getInstance()->serializer->serializeFieldValues($variant),
        ];
    }

    private function buildVariant(array $data, \craft\models\FieldLayout $variantLayout, Product $product): Variant
    {
        $variant = new Variant();
        $variant->setSku($data['sku'] ?? '');
        $variant->isDefault = (bool)($data['isDefault'] ?? false);
        if (isset($data['basePrice'])) {
            $variant->setBasePrice((float)$data['basePrice']);
        }
        foreach (['width', 'height', 'length', 'weight'] as $dim) {
            if (array_key_exists($dim, $data)) {
                $variant->$dim = $data[$dim];
            }
        }
        if (!empty($data['title'])) {
            $variant->title = $data['title'];
        }

        $commerce = Commerce::getInstance();
        if (!empty($data['taxCategory'])) {
            $variant->setTaxCategoryId($commerce->getTaxCategories()->getTaxCategoryByHandle($data['taxCategory'])?->id);
        }
        if (!empty($data['shippingCategory'])) {
            $variant->setShippingCategoryId($commerce->getShippingCategories()->getShippingCategoryByHandle($data['shippingCategory'])?->id);
        }

        $values = Plugin::getInstance()->normalizer->normalizeFieldValues($data['fields'] ?? [], $variantLayout, $variant);
        foreach ($values as $handle => $value) {
            $variant->setFieldValue($handle, $value);
        }

        return $variant;
    }

    public function collectReferences(ElementInterface $element): array
    {
        /** @var Product $element */
        $refs = [];
        foreach ($element->getVariants(true)->all() as $variant) {
            foreach (Plugin::getInstance()->serializer->collectFieldReferences($variant) as $uid) {
                $refs[] = $uid;
            }
        }
        return $refs;
    }
}
