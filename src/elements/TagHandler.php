<?php

namespace justinholtweb\transport\elements;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Tag;

/**
 * Element handler for tags. Resolves the tag group by handle.
 */
class TagHandler extends BaseElementHandler
{
    public function elementType(): string
    {
        return Tag::class;
    }

    public function packageKey(): string
    {
        return 'tags';
    }

    public function query(): ElementQueryInterface
    {
        return Tag::find()->status(null);
    }

    public function serializeAttributes(ElementInterface $element): array
    {
        /** @var Tag $element */
        return [
            'group' => $element->getGroup()->handle,
        ];
    }

    public function makeElement(array $attributes): ?ElementInterface
    {
        $group = isset($attributes['group'])
            ? Craft::$app->getTags()->getTagGroupByHandle($attributes['group'])
            : null;

        if (!$group) {
            return null;
        }

        $tag = new Tag();
        $tag->groupId = $group->id;

        return $tag;
    }
}
