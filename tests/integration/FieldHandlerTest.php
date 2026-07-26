<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;

/**
 * End-to-end field portability: relation fields carry UID references and Matrix fields
 * carry nested-entry content, both surviving a delete-and-reimport round trip.
 */
final class FieldHandlerTest extends TransportTestCase
{
    public function testRelationFieldSerializesToUidRefs(): void
    {
        $target = $this->makeCategory('Rel Target', 'fh-rel-target');
        $relation = $this->categoryRelationField();

        $group = $this->categoryGroup();
        $group->setFieldLayout($this->fieldLayout(Category::class, [$relation->handle]));
        Craft::$app->getCategories()->saveGroup($group);

        $source = new Category();
        $source->groupId = $group->id;
        $source->title = 'Rel Source';
        $source->slug = 'fh-rel-source';
        $source->setFieldValue($relation->handle, [$target->id]);
        $this->saveElement($source);

        $data = $this->plugin()->serializer->serializeElement($source);
        $refs = $data['sites'][$this->primarySiteHandle()]['fields'][$relation->handle];

        self::assertCount(1, $refs);
        self::assertSame($target->uid, $refs[0]['uid']);
        self::assertSame(Category::class, $refs[0]['type']);
    }

    public function testRelationFieldReconnectsOnReimport(): void
    {
        $target = $this->makeCategory('Keep Target', 'fh-keep-target');
        $relation = $this->categoryRelationField();

        $group = $this->categoryGroup();
        $group->setFieldLayout($this->fieldLayout(Category::class, [$relation->handle]));
        Craft::$app->getCategories()->saveGroup($group);

        $source = new Category();
        $source->groupId = $group->id;
        $source->title = 'Keep Source';
        $source->slug = 'fh-keep-source';
        $source->setFieldValue($relation->handle, [$target->id]);
        $this->saveElement($source);

        $path = $this->export(['categories'], 'fh-relation');

        // Delete only the source; the target survives so the UID still resolves.
        Craft::$app->getElements()->deleteElement($source, true);

        $this->plugin()->import->importPackage($path, false);

        $reimported = $this->findCategory('fh-keep-source');
        self::assertNotNull($reimported);
        $related = $reimported->getFieldValue($relation->handle)->all();
        self::assertCount(1, $related);
        self::assertSame($target->uid, $related[0]->uid, 'relation reconnected to the surviving target');
    }

    public function testMatrixFieldRoundTripsNestedContent(): void
    {
        $matrix = $this->matrixField();

        $section = $this->section([$matrix->handle]);
        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $section->getEntryTypes()[0]->id;
        $entry->title = 'Matrix Owner';
        $entry->slug = 'fh-matrix';
        $entry->setFieldValue($matrix->handle, [
            'sortOrder' => ['new1'],
            'entries' => [
                'new1' => [
                    'type' => 'transportBlock',
                    'enabled' => true,
                    'fields' => ['transportBlockText' => 'nested block text'],
                ],
            ],
        ]);
        $this->saveElement($entry);

        // Confirm the block serialized.
        $data = $this->plugin()->serializer->serializeElement($entry);
        $blocks = $data['sites'][$this->primarySiteHandle()]['fields'][$matrix->handle];
        self::assertCount(1, $blocks);
        self::assertSame('nested block text', $blocks[0]['fields']['transportBlockText']);

        $path = $this->export(['entries'], 'fh-matrix');
        $this->deleteEntries();

        $this->plugin()->import->importPackage($path, false);

        $reimported = $this->findEntry('fh-matrix');
        self::assertNotNull($reimported);
        $nested = $reimported->getFieldValue($matrix->handle)->all();
        self::assertCount(1, $nested);
        self::assertSame('nested block text', $nested[0]->getFieldValue('transportBlockText'));
    }

    public function testPlainTextUsesBaseHandler(): void
    {
        $body = $this->plainTextField();
        $handler = $this->plugin()->fieldRegistry->getHandler($body);

        self::assertInstanceOf(\justinholtweb\transport\fields\BaseFieldHandler::class, $handler);
    }

    public function testRelationFieldUsesRelationHandler(): void
    {
        $relation = $this->categoryRelationField();
        $handler = $this->plugin()->fieldRegistry->getHandler($relation);

        self::assertInstanceOf(\justinholtweb\transport\fields\RelationFieldHandler::class, $handler);
    }

    public function testMatrixFieldUsesMatrixHandler(): void
    {
        $matrix = $this->matrixField();
        $handler = $this->plugin()->fieldRegistry->getHandler($matrix);

        self::assertInstanceOf(\justinholtweb\transport\fields\MatrixFieldHandler::class, $handler);
    }

    private function categoryRelationField(): \craft\fields\Categories
    {
        $handle = 'fhRelated';
        $existing = Craft::$app->getFields()->getFieldByHandle($handle);
        if ($existing instanceof \craft\fields\Categories) {
            return $existing;
        }

        $field = new \craft\fields\Categories();
        $field->name = 'FH Related';
        $field->handle = $handle;
        $field->source = 'group:' . $this->categoryGroup()->uid;

        if (!Craft::$app->getFields()->saveField($field)) {
            self::fail('Could not save relation field: ' . implode('; ', $field->getFirstErrors()));
        }

        return Craft::$app->getFields()->getFieldByHandle($handle);
    }
}
