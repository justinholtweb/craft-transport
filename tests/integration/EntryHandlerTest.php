<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use Craft;
use craft\elements\Entry;

/**
 * Entry-specific attribute portability: section/type resolution by handle, author and
 * structure-parent references by UID, and post/expiry dates.
 */
final class EntryHandlerTest extends TransportTestCase
{
    public function testSectionAndTypeSerializeAsHandles(): void
    {
        $entry = $this->makeEntry('Handled', 'eh-handles');
        $data = $this->plugin()->serializer->serializeElement($entry);

        self::assertSame(self::SECTION, $data['attributes']['section']);
        self::assertSame(self::ENTRY_TYPE, $data['attributes']['type']);
    }

    public function testPostDateRoundTrips(): void
    {
        $entry = $this->makeEntry('Dated', 'eh-dated');
        $entry->postDate = new \DateTime('2025-06-15 12:00:00', new \DateTimeZone('UTC'));
        Craft::$app->getElements()->saveElement($entry);
        $expected = $this->findEntry('eh-dated')->postDate->getTimestamp();

        $path = $this->export(['entries'], 'eh-date');
        $this->deleteEntries();
        $this->plugin()->import->importPackage($path, false);

        $reimported = $this->findEntry('eh-dated');
        self::assertNotNull($reimported);
        // Compare the actual instant (tz-independent) rather than a formatted string.
        self::assertSame($expected, $reimported->postDate->getTimestamp());
    }

    public function testAuthorReferenceRoundTripsByUid(): void
    {
        $author = $this->admin();
        $entry = $this->makeEntry('Authored', 'eh-author');
        $entry->setAuthorIds([$author->id]);
        Craft::$app->getElements()->saveElement($entry);

        $data = $this->plugin()->serializer->serializeElement($entry);
        self::assertContains($author->uid, $data['attributes']['authors']);

        $path = $this->export(['entries'], 'eh-author');
        $this->deleteEntries();
        $this->plugin()->import->importPackage($path, false);

        $reimported = $this->findEntry('eh-author');
        self::assertNotNull($reimported);
        $authorIds = array_map(static fn($a) => $a->uid, $reimported->getAuthors());
        self::assertContains($author->uid, $authorIds, 'author relinked by UID');
    }

    public function testMakeElementReturnsNullForUnknownSection(): void
    {
        $handler = $this->plugin()->elementRegistry->getHandlerForType(Entry::class);

        self::assertNull($handler->makeElement(['section' => 'ghost', 'type' => 'ghost']));
    }

    public function testCollectReferencesIncludesParent(): void
    {
        // Structured section needed for parents; the default fixture section is a
        // channel, so this asserts the handler contract on a parent-bearing entry via
        // a structure section.
        $section = $this->structureSection();
        $parent = new Entry();
        $parent->sectionId = $section->id;
        $parent->typeId = $section->getEntryTypes()[0]->id;
        $parent->title = 'Parent';
        $parent->slug = 'eh-struct-parent';
        $this->saveElement($parent);

        $child = new Entry();
        $child->sectionId = $section->id;
        $child->typeId = $section->getEntryTypes()[0]->id;
        $child->title = 'Child';
        $child->slug = 'eh-struct-child';
        $child->setParentId($parent->id);
        $this->saveElement($child);

        $refs = $this->plugin()->serializer->collectReferences($child);

        self::assertContains($parent->uid, $refs);
    }

    private function admin(): \craft\elements\User
    {
        $admin = \craft\elements\User::find()->admin()->status(null)->one();
        if ($admin) {
            return $admin;
        }

        $user = new \craft\elements\User();
        $user->username = 'transport-admin';
        $user->email = 'transport-admin@example.test';
        $user->admin = true;
        $this->saveElement($user);

        return $user;
    }

    private function structureSection(): \craft\models\Section
    {
        $handle = 'transportStruct';
        $entries = Craft::$app->getEntries();
        $existing = $entries->getSectionByHandle($handle);
        if ($existing) {
            return $existing;
        }

        $entryType = new \craft\models\EntryType();
        $entryType->name = 'Struct Type';
        $entryType->handle = 'transportStructType';
        if (!$entries->saveEntryType($entryType)) {
            self::fail('entry type: ' . implode('; ', $entryType->getFirstErrors()));
        }

        $section = new \craft\models\Section();
        $section->name = 'Transport Struct';
        $section->handle = $handle;
        $section->type = \craft\models\Section::TYPE_STRUCTURE;
        $section->propagationMethod = \craft\enums\PropagationMethod::All;

        $siteSettings = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings[] = new \craft\models\Section_SiteSettings([
                'siteId' => $site->id,
                'hasUrls' => false,
            ]);
        }
        $section->setSiteSettings($siteSettings);
        $section->setEntryTypes([$entryType]);

        if (!$entries->saveSection($section)) {
            self::fail('section: ' . implode('; ', $section->getFirstErrors()));
        }

        return $entries->getSectionByHandle($handle);
    }
}
