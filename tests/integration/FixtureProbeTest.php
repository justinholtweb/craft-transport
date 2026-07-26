<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use Craft;

final class FixtureProbeTest extends TransportTestCase
{
    public function testSchemaFixturesBuild(): void
    {
        $field = $this->plainTextField();
        self::assertNotNull(Craft::$app->getFields()->getFieldByHandle($field->handle));

        $group = $this->categoryGroup([$field->handle]);
        self::assertSame(self::CATEGORY_GROUP, $group->handle);

        $section = $this->section([$field->handle]);
        self::assertCount(1, $section->getEntryTypes());

        self::assertSame(self::TAG_GROUP, $this->tagGroup()->handle);
        self::assertSame(self::VOLUME, $this->volume()->handle);
        self::assertNotNull($this->matrixField());
    }

    public function testElementFixturesSave(): void
    {
        $this->plainTextField();
        $category = $this->makeCategory('Probe Cat', 'probe-cat', null, ['transportBody' => 'cat body']);
        self::assertNotNull($this->findCategory('probe-cat'));
        self::assertSame('cat body', $category->getFieldValue('transportBody'));

        $entry = $this->makeEntry('Probe Entry', 'probe-entry', ['transportBody' => 'entry body']);
        self::assertNotNull($this->findEntry('probe-entry'));
        self::assertSame('entry body', $entry->getFieldValue('transportBody'));
    }

    public function testTransactionIsolationRollsBackSchema(): void
    {
        // The previous tests created these; each test runs in its own transaction, so
        // they must not be visible here.
        self::assertNull(Craft::$app->getCategories()->getGroupByHandle(self::CATEGORY_GROUP));
        self::assertNull($this->findCategory('probe-cat'));
    }
}
