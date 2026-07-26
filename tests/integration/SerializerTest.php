<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use Craft;
use craft\elements\Category;

/**
 * Serialization of elements into Transport's portable, UID-based representation,
 * plus reference collection used for dependency ordering.
 */
final class SerializerTest extends TransportTestCase
{
    public function testEnvelopeShape(): void
    {
        $this->plainTextField();
        $category = $this->makeCategory('Envelope', 'ser-envelope', null, ['transportBody' => 'hello']);

        $data = $this->plugin()->serializer->serializeElement($category);

        self::assertSame($category->uid, $data['uid']);
        self::assertSame(Category::class, $data['type']);
        self::assertSame('categories', $data['key']);
        self::assertArrayHasKey('attributes', $data);
        self::assertArrayHasKey('sites', $data);

        $siteData = $data['sites'][$this->primarySiteHandle()];
        self::assertSame('Envelope', $siteData['title']);
        self::assertSame('ser-envelope', $siteData['slug']);
        self::assertSame('hello', $siteData['fields']['transportBody']);
    }

    public function testAttributesAreHandleBasedNotIdBased(): void
    {
        $category = $this->makeCategory('Attr', 'ser-attr');

        $data = $this->plugin()->serializer->serializeElement($category);

        // The group is carried as its handle, never a local ID.
        self::assertSame(self::CATEGORY_GROUP, $data['attributes']['group']);
        self::assertArrayNotHasKey('groupId', $data['attributes']);
    }

    public function testParentReferenceIsSerializedAsUid(): void
    {
        $parent = $this->makeCategory('Parent', 'ser-parent');
        $child = $this->makeCategory('Child', 'ser-child', $parent->id);

        $data = $this->plugin()->serializer->serializeElement($child);

        self::assertSame($parent->uid, $data['attributes']['parent']);
    }

    public function testCollectReferencesIncludesRelationTargets(): void
    {
        $this->plainTextField();
        // Build a category that relates to another via a relation field.
        $relationField = $this->relationField();
        $target = $this->makeCategory('Target', 'ser-rel-target');

        $group = $this->categoryGroup();
        $group->setFieldLayout($this->fieldLayout(Category::class, [$relationField->handle]));
        Craft::$app->getCategories()->saveGroup($group);

        $source = new Category();
        $source->groupId = $group->id;
        $source->title = 'Source';
        $source->slug = 'ser-rel-source';
        $source->setFieldValue($relationField->handle, [$target->id]);
        $this->saveElement($source);

        $refs = $this->plugin()->serializer->collectReferences($source);

        self::assertContains($target->uid, $refs);
    }

    public function testSerializeUnsupportedTypeFallsBackToElementsKey(): void
    {
        // Addresses have a handler; a bare element with no handler would use 'elements'.
        // Here we assert the handler-driven key for a known type instead.
        $category = $this->makeCategory('Keyed', 'ser-keyed');
        $data = $this->plugin()->serializer->serializeElement($category);
        self::assertSame('categories', $data['key']);
    }

    /**
     * Creates a category-relation field for the reference test.
     */
    private function relationField(): \craft\fields\Categories
    {
        $handle = 'transportRelated';
        $existing = Craft::$app->getFields()->getFieldByHandle($handle);
        if ($existing instanceof \craft\fields\Categories) {
            return $existing;
        }

        $field = new \craft\fields\Categories();
        $field->name = 'Related';
        $field->handle = $handle;
        $field->source = 'group:' . $this->categoryGroup()->uid;

        if (!Craft::$app->getFields()->saveField($field)) {
            self::fail('Could not save relation field: ' . implode('; ', $field->getFirstErrors()));
        }

        return Craft::$app->getFields()->getFieldByHandle($handle);
    }
}
