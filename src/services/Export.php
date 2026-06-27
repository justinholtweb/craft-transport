<?php

namespace justinholtweb\transport\services;

use Craft;
use craft\base\ElementInterface;
use justinholtweb\transport\models\ExportConfig;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\records\ImportHistory;
use yii\base\Component;

/**
 * Orchestrates the export flow: gather elements across selected types, resolve
 * dependency order, serialize, bundle asset files, write a package, and record history.
 */
class Export extends Component
{
    /**
     * Runs an export and returns the absolute path to the written package.
     */
    public function export(ExportConfig $config): string
    {
        $plugin = Plugin::getInstance();
        $serializer = $plugin->serializer;

        $elements = $this->gatherElements($config);

        // Resolve a dependency-safe import order across the whole set.
        $resolution = $plugin->dependencies->resolve($elements);

        $grouped = [];
        foreach ($elements as $element) {
            $data = $serializer->serializeElement($element);
            $grouped[$data['key']][] = $data;
        }

        $files = $config->includeAssetFiles
            ? $plugin->assets->filesForElements($elements)
            : [];

        $path = $plugin->packages->write($grouped, $files, $config->packageName, [
            'importOrder' => $resolution['order'],
            'cycles' => $resolution['cycles'],
        ]);

        $this->recordHistory($path, $grouped);

        return $path;
    }

    /**
     * Resolves the configured scope into a flat list of elements across every selected
     * element type.
     *
     * @return ElementInterface[]
     */
    private function gatherElements(ExportConfig $config): array
    {
        $registry = Plugin::getInstance()->elementRegistry;

        $siteId = $config->site
            ? Craft::$app->getSites()->getSiteByHandle($config->site)?->id
            : Craft::$app->getSites()->getPrimarySite()->id;

        $elements = [];

        foreach ($config->packageKeys as $key) {
            $handler = $registry->getHandlerForKey($key);
            if ($handler === null) {
                continue;
            }

            $query = $handler->query()->siteId($siteId);

            if ($key === 'entries') {
                if (!empty($config->elementIds)) {
                    $query->id($config->elementIds);
                } elseif ($config->section) {
                    $query->section($config->section);
                }
            }

            $elements = array_merge($elements, $query->all());
        }

        return $elements;
    }

    private function recordHistory(string $path, array $grouped): void
    {
        $counts = [];
        foreach ($grouped as $key => $elements) {
            $counts[$key] = count($elements);
        }

        $record = new ImportHistory();
        $record->packageName = basename($path);
        $record->direction = ImportHistory::DIRECTION_EXPORT;
        $record->status = ImportHistory::STATUS_COMPLETED;
        $record->elementCounts = $counts;
        $record->userId = Craft::$app->getUser()->getId();
        $record->save();
    }
}
