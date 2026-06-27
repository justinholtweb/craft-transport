<?php

namespace justinholtweb\transport\elements;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use justinholtweb\transport\helpers\IdentityHelper;

/**
 * Element handler for entries.
 *
 * Resolves sections, entry types, authors and structure parents by handle/UID so an
 * exported entry can be recreated in another environment.
 */
class EntryHandler extends BaseElementHandler
{
    public function elementType(): string
    {
        return Entry::class;
    }

    public function packageKey(): string
    {
        return 'entries';
    }

    public function query(): ElementQueryInterface
    {
        // Exclude drafts and revisions; export canonical content only.
        return Entry::find()
            ->drafts(false)
            ->revisions(false)
            ->status(null);
    }

    public function serializeAttributes(ElementInterface $element): array
    {
        /** @var Entry $element */
        $parent = $element->getParentId() ? $element->getParent() : null;

        return [
            'section' => $element->getSection()?->handle,
            'type' => $element->getType()->handle,
            'authors' => array_map(
                static fn($author) => $author->uid,
                $element->getAuthors()
            ),
            'parent' => $parent?->uid,
            'postDate' => $element->postDate?->format(DATE_ATOM),
            'expiryDate' => $element->expiryDate?->format(DATE_ATOM),
        ];
    }

    public function makeElement(array $attributes): ?ElementInterface
    {
        $section = isset($attributes['section'])
            ? Craft::$app->getEntries()->getSectionByHandle($attributes['section'])
            : null;

        if (!$section) {
            return null;
        }

        $entryType = null;
        foreach ($section->getEntryTypes() as $type) {
            if ($type->handle === ($attributes['type'] ?? null)) {
                $entryType = $type;
                break;
            }
        }

        if (!$entryType) {
            return null;
        }

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;

        return $entry;
    }

    public function applyAttributes(array $attributes, ElementInterface $element): void
    {
        /** @var Entry $element */
        if (!empty($attributes['postDate'])) {
            $element->postDate = DateTimeHelper::toDateTime($attributes['postDate']) ?: null;
        }
        if (!empty($attributes['expiryDate'])) {
            $element->expiryDate = DateTimeHelper::toDateTime($attributes['expiryDate']) ?: null;
        }

        $authorIds = [];
        foreach ($attributes['authors'] ?? [] as $authorUid) {
            $id = IdentityHelper::resolveId($authorUid, \craft\elements\User::class);
            if ($id !== null) {
                $authorIds[] = $id;
            }
        }
        if ($authorIds) {
            $element->setAuthorIds($authorIds);
        }

        if (!empty($attributes['parent'])) {
            $parentId = IdentityHelper::resolveId($attributes['parent'], Entry::class);
            if ($parentId !== null) {
                $element->setParentId($parentId);
            }
        }
    }

    public function collectReferences(ElementInterface $element): array
    {
        /** @var Entry $element */
        $refs = array_map(static fn($author) => $author->uid, $element->getAuthors());

        if ($element->getParentId()) {
            $parent = $element->getParent();
            if ($parent) {
                $refs[] = $parent->uid;
            }
        }

        return $refs;
    }
}
