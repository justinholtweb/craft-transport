<?php

namespace justinholtweb\transport\tests\integration;

use Craft;
use craft\elements\Category;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\models\ExportConfig;
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests that exercise the live export/import pipeline.
 *
 * Provides a self-contained category group fixture so the tests don't depend on any
 * particular site's schema.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected const GROUP_HANDLE = 'transportItGroup';

    protected function plugin(): Plugin
    {
        return Plugin::getInstance();
    }

    protected function ensureGroup(): CategoryGroup
    {
        $group = Craft::$app->getCategories()->getGroupByHandle(self::GROUP_HANDLE);
        if ($group) {
            return $group;
        }

        $group = new CategoryGroup();
        $group->name = 'Transport IT Group';
        $group->handle = self::GROUP_HANDLE;
        $siteSettings = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings[$site->id] = new CategoryGroup_SiteSettings(['siteId' => $site->id, 'hasUrls' => false]);
        }
        $group->setSiteSettings($siteSettings);
        Craft::$app->getCategories()->saveGroup($group);

        return Craft::$app->getCategories()->getGroupByHandle(self::GROUP_HANDLE);
    }

    protected function makeCategory(string $title, string $slug, ?int $parentId = null): Category
    {
        $category = new Category();
        $category->groupId = $this->ensureGroup()->id;
        $category->title = $title;
        $category->slug = $slug;
        if ($parentId) {
            $category->setParentId($parentId);
        }
        Craft::$app->getElements()->saveElement($category);

        return $category;
    }

    protected function findCategory(string $slug): ?Category
    {
        return Category::find()->group(self::GROUP_HANDLE)->slug($slug)->status(null)->one();
    }

    protected function exportCategories(string $name): string
    {
        $config = new ExportConfig();
        $config->packageKeys = ['categories'];
        $config->packageName = $name;
        return $this->plugin()->export->export($config);
    }

    protected function deleteAllTestCategories(): void
    {
        foreach (Category::find()->group(self::GROUP_HANDLE)->status(null)->all() as $category) {
            Craft::$app->getElements()->deleteElement($category, true);
        }
    }
}
