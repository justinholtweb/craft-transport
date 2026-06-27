<?php

namespace justinholtweb\transport\elements;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\transport\helpers\IdentityHelper;

/**
 * Element handler for categories. Resolves the category group by handle and preserves
 * structure parents.
 */
class CategoryHandler extends BaseElementHandler
{
    public function elementType(): string
    {
        return Category::class;
    }

    public function packageKey(): string
    {
        return 'categories';
    }

    public function query(): ElementQueryInterface
    {
        return Category::find()->status(null);
    }

    public function serializeAttributes(ElementInterface $element): array
    {
        /** @var Category $element */
        $parent = $element->getParentId() ? $element->getParent() : null;

        return [
            'group' => $element->getGroup()->handle,
            'parent' => $parent?->uid,
        ];
    }

    public function makeElement(array $attributes): ?ElementInterface
    {
        $group = isset($attributes['group'])
            ? Craft::$app->getCategories()->getGroupByHandle($attributes['group'])
            : null;

        if (!$group) {
            return null;
        }

        $category = new Category();
        $category->groupId = $group->id;

        return $category;
    }

    public function applyAttributes(array $attributes, ElementInterface $element): void
    {
        /** @var Category $element */
        if (!empty($attributes['parent'])) {
            $parentId = IdentityHelper::resolveId($attributes['parent'], Category::class);
            if ($parentId !== null) {
                $element->setParentId($parentId);
            }
        }
    }

    public function collectReferences(ElementInterface $element): array
    {
        /** @var Category $element */
        if ($element->getParentId()) {
            $parent = $element->getParent();
            if ($parent) {
                return [$parent->uid];
            }
        }

        return [];
    }
}
