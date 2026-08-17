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

    /**
     * A tag is identified by its slug within its group.
     */
    public function matchExisting(array $data, ?int $siteId = null): ?ElementInterface
    {
        $handle = $data['attributes']['group'] ?? null;
        $group = $handle ? Craft::$app->getTags()->getTagGroupByHandle($handle) : null;
        $slugs = $this->slugsFrom($data);

        if (!$group || !$slugs) {
            return null;
        }

        $query = Tag::find()
            ->group($group->handle)
            ->slug($slugs)
            ->status(null)
            ->orderBy(['elements.id' => SORT_ASC]);

        return $this->scopeToSite($query, $siteId)->one();
    }
}
