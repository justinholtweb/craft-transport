<?php

namespace justinholtweb\transport\elements\thirdparty;

use Craft;
use craft\base\ElementInterface;
use justinholtweb\transport\elements\BaseElementHandler;
use justinholtweb\transport\helpers\IdentityHelper;
use verbb\navigation\elements\Node;
use verbb\navigation\Navigation;

/**
 * Element handler for Verbb Navigation nodes.
 *
 * A node belongs to a navigation (resolved by handle), is structured (parent/child),
 * and either links to an element (made portable via UID) or holds a manual URL.
 * The navigation itself must already exist in the target, like a section.
 *
 * Only registered when verbb/navigation is installed.
 */
class NavigationNodeHandler extends BaseElementHandler
{
    public function elementType(): string
    {
        return Node::class;
    }

    public function packageKey(): string
    {
        return 'navnodes';
    }

    public function query(): \craft\elements\db\ElementQueryInterface
    {
        return Node::find()->status(null);
    }

    public function serializeAttributes(ElementInterface $element): array
    {
        /** @var Node $element */
        $parent = $element->getParentId() ? $element->getParent() : null;

        $attributes = [
            'nav' => $element->getNav()->handle,
            'type' => $element->type,
            'url' => $element->getRawUrl(),
            'classes' => $element->classes,
            'urlSuffix' => $element->urlSuffix,
            'newWindow' => $element->newWindow,
            'parent' => $parent?->uid,
            'element' => null,
        ];

        if ($element->isElement() && $element->elementId) {
            $linked = Craft::$app->getElements()->getElementById($element->elementId, $element->type, null);
            if ($linked) {
                $attributes['element'] = ['uid' => $linked->uid, 'type' => $linked::class];
            }
        }

        return $attributes;
    }

    public function makeElement(array $attributes): ?ElementInterface
    {
        $nav = isset($attributes['nav'])
            ? Navigation::$plugin->getNavs()->getNavByHandle($attributes['nav'])
            : null;

        if (!$nav) {
            return null;
        }

        $node = new Node();
        $node->navId = $nav->id;
        $node->structureId = $nav->structureId;

        return $node;
    }

    public function applyAttributes(array $attributes, ElementInterface $element): void
    {
        /** @var Node $element */
        $element->type = $attributes['type'] ?? $element->type;
        $element->url = $attributes['url'] ?? null;
        $element->classes = $attributes['classes'] ?? null;
        $element->urlSuffix = $attributes['urlSuffix'] ?? null;
        $element->newWindow = (bool)($attributes['newWindow'] ?? false);

        if (!empty($attributes['element']['uid'])) {
            $element->elementId = IdentityHelper::resolveId(
                $attributes['element']['uid'],
                $attributes['element']['type']
            );
        }

        if (!empty($attributes['parent'])) {
            $parentId = IdentityHelper::resolveId($attributes['parent'], Node::class);
            if ($parentId !== null) {
                $element->setParentId($parentId);
            }
        }
    }

    public function collectReferences(ElementInterface $element): array
    {
        /** @var Node $element */
        $refs = [];
        if ($element->isElement() && $element->elementId) {
            $linked = Craft::$app->getElements()->getElementById($element->elementId, $element->type, null);
            if ($linked) {
                $refs[] = $linked->uid;
            }
        }
        if ($element->getParentId() && $element->getParent()) {
            $refs[] = $element->getParent()->uid;
        }
        return $refs;
    }
}
