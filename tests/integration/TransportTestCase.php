<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\enums\PropagationMethod;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fs\Local;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\TagGroup;
use craft\models\Volume;
use craft\test\TestCase;
use justinholtweb\transport\models\ExportConfig;
use justinholtweb\transport\Plugin;

/**
 * Base class for Transport's integration tests.
 *
 * Every test runs inside a transaction (see tests/integration.suite.yml), so the
 * schema these helpers create — sections, groups, fields, volumes — is rolled back
 * afterwards. They are therefore built on demand and cached only for the lifetime of
 * a single test.
 */
abstract class TransportTestCase extends TestCase
{
    protected const CATEGORY_GROUP = 'transportCats';
    protected const TAG_GROUP = 'transportTags';
    protected const SECTION = 'transportNews';
    protected const ENTRY_TYPE = 'transportArticle';
    protected const VOLUME = 'transportFiles';

    protected function plugin(): Plugin
    {
        return Plugin::getInstance();
    }

    protected function primarySiteHandle(): string
    {
        return Craft::$app->getSites()->getPrimarySite()->handle;
    }

    // ------------------------------------------------------------------
    // Schema fixtures
    // ------------------------------------------------------------------

    /**
     * A plain text field, created on demand.
     */
    protected function plainTextField(string $handle = 'transportBody'): PlainText
    {
        $existing = Craft::$app->getFields()->getFieldByHandle($handle);
        if ($existing instanceof PlainText) {
            return $existing;
        }

        $field = new PlainText();
        $field->name = ucfirst($handle);
        $field->handle = $handle;
        $field->multiline = true;

        if (!Craft::$app->getFields()->saveField($field)) {
            self::fail("Couldn't save field $handle: " . implode('; ', $field->getFirstErrors()));
        }

        return Craft::$app->getFields()->getFieldByHandle($handle);
    }

    /**
     * A structured category group with an optional field layout.
     *
     * @param string[] $fieldHandles Custom field handles to include in the layout.
     */
    protected function categoryGroup(array $fieldHandles = []): CategoryGroup
    {
        $categories = Craft::$app->getCategories();
        $existing = $categories->getGroupByHandle(self::CATEGORY_GROUP);
        if ($existing) {
            return $existing;
        }

        $group = new CategoryGroup();
        $group->name = 'Transport Cats';
        $group->handle = self::CATEGORY_GROUP;

        $siteSettings = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings[$site->id] = new CategoryGroup_SiteSettings([
                'siteId' => $site->id,
                'hasUrls' => false,
            ]);
        }
        $group->setSiteSettings($siteSettings);

        if ($fieldHandles) {
            $group->setFieldLayout($this->fieldLayout(Category::class, $fieldHandles));
        }

        if (!$categories->saveGroup($group)) {
            self::fail("Couldn't save category group: " . implode('; ', $group->getFirstErrors()));
        }

        return $categories->getGroupByHandle(self::CATEGORY_GROUP);
    }

    protected function tagGroup(): TagGroup
    {
        $tags = Craft::$app->getTags();
        $existing = $tags->getTagGroupByHandle(self::TAG_GROUP);
        if ($existing) {
            return $existing;
        }

        $group = new TagGroup();
        $group->name = 'Transport Tags';
        $group->handle = self::TAG_GROUP;

        if (!$tags->saveTagGroup($group)) {
            self::fail("Couldn't save tag group: " . implode('; ', $group->getFirstErrors()));
        }

        return $tags->getTagGroupByHandle(self::TAG_GROUP);
    }

    /**
     * A channel section with a single entry type, optionally carrying custom fields.
     *
     * @param string[] $fieldHandles
     */
    protected function section(array $fieldHandles = []): Section
    {
        $entries = Craft::$app->getEntries();
        $existing = $entries->getSectionByHandle(self::SECTION);
        if ($existing) {
            return $existing;
        }

        $entryType = new EntryType();
        $entryType->name = 'Transport Article';
        $entryType->handle = self::ENTRY_TYPE;
        if ($fieldHandles) {
            $entryType->setFieldLayout($this->fieldLayout(Entry::class, $fieldHandles));
        }

        if (!$entries->saveEntryType($entryType)) {
            self::fail("Couldn't save entry type: " . implode('; ', $entryType->getFirstErrors()));
        }

        $section = new Section();
        $section->name = 'Transport News';
        $section->handle = self::SECTION;
        $section->type = Section::TYPE_CHANNEL;
        $section->propagationMethod = PropagationMethod::All;

        $siteSettings = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings[] = new Section_SiteSettings([
                'siteId' => $site->id,
                'hasUrls' => false,
                'enabledByDefault' => true,
            ]);
        }
        $section->setSiteSettings($siteSettings);
        $section->setEntryTypes([$entryType]);

        if (!$entries->saveSection($section)) {
            self::fail("Couldn't save section: " . implode('; ', $section->getFirstErrors()));
        }

        return $entries->getSectionByHandle(self::SECTION);
    }

    /**
     * A local filesystem volume backed by a throwaway directory under tests/_output.
     */
    protected function volume(): Volume
    {
        $volumes = Craft::$app->getVolumes();
        $existing = $volumes->getVolumeByHandle(self::VOLUME);
        if ($existing) {
            return $existing;
        }

        // Craft forbids local filesystems inside or above its system directories, so
        // stage the throwaway volume outside the project tree entirely.
        $basePath = sys_get_temp_dir() . '/transport-test-volume';
        if (!is_dir($basePath)) {
            mkdir($basePath, 0777, true);
        }

        $fs = new Local();
        $fs->name = 'Transport Files';
        $fs->handle = self::VOLUME;
        $fs->path = $basePath;
        $fs->hasUrls = false;

        if (!Craft::$app->getFs()->saveFilesystem($fs)) {
            self::fail("Couldn't save filesystem: " . implode('; ', $fs->getFirstErrors()));
        }

        $volume = new Volume();
        $volume->name = 'Transport Files';
        $volume->handle = self::VOLUME;
        $volume->setFsHandle(self::VOLUME);

        if (!$volumes->saveVolume($volume)) {
            self::fail("Couldn't save volume: " . implode('; ', $volume->getFirstErrors()));
        }

        return $volumes->getVolumeByHandle(self::VOLUME);
    }

    /**
     * A Matrix field whose nested entry type carries a single plain text field.
     */
    protected function matrixField(string $handle = 'transportBlocks'): Matrix
    {
        $existing = Craft::$app->getFields()->getFieldByHandle($handle);
        if ($existing instanceof Matrix) {
            return $existing;
        }

        $inner = $this->plainTextField('transportBlockText');

        $blockType = new EntryType();
        $blockType->name = 'Transport Block';
        $blockType->handle = 'transportBlock';
        $blockType->setFieldLayout($this->fieldLayout(Entry::class, [$inner->handle]));

        if (!Craft::$app->getEntries()->saveEntryType($blockType)) {
            self::fail("Couldn't save block entry type: " . implode('; ', $blockType->getFirstErrors()));
        }

        $field = new Matrix();
        $field->name = 'Transport Blocks';
        $field->handle = $handle;
        $field->setEntryTypes([$blockType]);

        if (!Craft::$app->getFields()->saveField($field)) {
            self::fail("Couldn't save Matrix field: " . implode('; ', $field->getFirstErrors()));
        }

        return Craft::$app->getFields()->getFieldByHandle($handle);
    }

    /**
     * @param string[] $fieldHandles
     */
    protected function fieldLayout(string $elementType, array $fieldHandles): FieldLayout
    {
        $elements = [];

        foreach ($fieldHandles as $handle) {
            $field = Craft::$app->getFields()->getFieldByHandle($handle);
            if (!$field) {
                self::fail("Unknown field \"$handle\" — create it before building a layout.");
            }
            $elements[] = ['type' => \craft\fieldlayoutelements\CustomField::class, 'fieldUid' => $field->uid];
        }

        $layout = new FieldLayout(['type' => $elementType]);
        // Pass tabs as array config so setTabs() wires each tab's parent layout before
        // constructing its elements (a FieldLayoutTab can't build elements otherwise).
        $layout->setTabs([
            ['name' => 'Content', 'elements' => $elements],
        ]);

        return $layout;
    }

    // ------------------------------------------------------------------
    // Element fixtures
    // ------------------------------------------------------------------

    protected function makeCategory(string $title, string $slug, ?int $parentId = null, array $fields = []): Category
    {
        $category = new Category();
        $category->groupId = $this->categoryGroup(array_keys($fields))->id;
        $category->title = $title;
        $category->slug = $slug;
        if ($parentId !== null) {
            $category->setParentId($parentId);
        }
        foreach ($fields as $handle => $value) {
            $category->setFieldValue($handle, $value);
        }

        $this->saveElement($category);

        return $category;
    }

    protected function makeEntry(string $title, string $slug, array $fields = []): Entry
    {
        $section = $this->section(array_keys($fields));

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $section->getEntryTypes()[0]->id;
        $entry->title = $title;
        $entry->slug = $slug;
        foreach ($fields as $handle => $value) {
            $entry->setFieldValue($handle, $value);
        }

        $this->saveElement($entry);

        return $entry;
    }

    protected function saveElement(\craft\base\ElementInterface $element): void
    {
        if (!Craft::$app->getElements()->saveElement($element)) {
            self::fail(sprintf(
                'Failed to save %s: %s',
                $element::class,
                implode('; ', $element->getFirstErrors())
            ));
        }
    }

    protected function findCategory(string $slug): ?Category
    {
        return Category::find()->group(self::CATEGORY_GROUP)->slug($slug)->status(null)->one();
    }

    protected function findEntry(string $slug): ?Entry
    {
        return Entry::find()->section(self::SECTION)->slug($slug)->status(null)->one();
    }

    protected function deleteCategories(): void
    {
        foreach (Category::find()->group(self::CATEGORY_GROUP)->status(null)->all() as $category) {
            Craft::$app->getElements()->deleteElement($category, true);
        }
    }

    protected function deleteEntries(): void
    {
        foreach (Entry::find()->section(self::SECTION)->status(null)->all() as $entry) {
            Craft::$app->getElements()->deleteElement($entry, true);
        }
    }

    // ------------------------------------------------------------------
    // Pipeline helpers
    // ------------------------------------------------------------------

    /**
     * Exports the given package keys and returns the package path. Paths are tracked
     * and removed in tearDown.
     *
     * @param string[] $keys
     */
    protected function export(array $keys, ?string $name = null, array $configOverrides = []): string
    {
        $config = new ExportConfig();
        $config->packageKeys = $keys;
        $config->packageName = $name ?? ('test-' . implode('-', $keys) . '-' . count($this->packagePaths));
        foreach ($configOverrides as $attribute => $value) {
            $config->$attribute = $value;
        }

        return $this->trackPackage($this->plugin()->export->export($config));
    }

    /**
     * Registers a package path for automatic cleanup in tearDown. Returns the path so
     * callers building their own ExportConfig can wrap the export call inline.
     */
    protected function trackPackage(string $path): string
    {
        $this->packagePaths[] = $path;
        return $path;
    }

    /** @var string[] */
    private array $packagePaths = [];

    protected function _after(): void
    {
        foreach ($this->packagePaths as $path) {
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
        $this->packagePaths = [];

        parent::_after();
    }
}
