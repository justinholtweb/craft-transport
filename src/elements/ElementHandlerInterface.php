<?php

namespace justinholtweb\transport\elements;

use craft\base\ElementInterface;

/**
 * Contract for exporting/importing a specific element type.
 *
 * The {@see \justinholtweb\transport\services\Serializer} owns the common envelope
 * (uid, type, title, slug, status, per-site field values); a handler is responsible
 * only for type-specific attributes (a section + entry type for entries, a group for
 * categories, a volume + folder for assets, and so on).
 */
interface ElementHandlerInterface
{
    /**
     * The fully-qualified element class this handler is responsible for.
     */
    public function elementType(): string;

    /**
     * A stable, human-friendly key used as the package filename / grouping bucket,
     * e.g. "entries", "categories", "assets".
     */
    public function packageKey(): string;

    /**
     * Returns an element query scoped to the elements this handler should consider for
     * export. Callers further constrain it (by id, section, site, etc.).
     */
    public function query(): \craft\elements\db\ElementQueryInterface;

    /**
     * Serializes type-specific attributes (everything beyond the common envelope) into
     * a portable, UID/handle-based array.
     */
    public function serializeAttributes(ElementInterface $element): array;

    /**
     * Instantiates a new, unsaved element configured from the portable attributes
     * (e.g. resolves the section + entry type for an entry). Returns null when the
     * target environment can't host the element (missing section, etc.).
     */
    public function makeElement(array $attributes): ?ElementInterface;

    /**
     * Applies portable attributes onto an existing or newly-created element.
     */
    public function applyAttributes(array $attributes, ElementInterface $element): void;

    /**
     * UID references this element depends on through its own attributes (author,
     * parent, section structure, etc.) — excludes custom-field references, which the
     * field handlers report.
     *
     * @return string[]
     */
    public function collectReferences(ElementInterface $element): array;
}
