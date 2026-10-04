<?php

namespace justinholtweb\transport\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\EntryQuery;
use justinholtweb\transport\events\AfterExportEvent;
use justinholtweb\transport\events\BeforeExportEvent;
use justinholtweb\transport\helpers\Access;
use justinholtweb\transport\models\ExportConfig;
use justinholtweb\transport\models\TransportReport;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\progress\NullProgress;
use justinholtweb\transport\progress\ProgressInterface;
use justinholtweb\transport\records\ImportHistory;
use Throwable;
use yii\base\Component;

/**
 * Orchestrates the export flow: gather elements across selected types, resolve
 * dependency order, serialize, bundle asset files, write a package, and record history.
 */
class Export extends Component
{
    /** Raised before an export runs; listeners may adjust or cancel it. */
    public const EVENT_BEFORE_EXPORT = 'beforeExport';

    /** Raised after a package is written. */
    public const EVENT_AFTER_EXPORT = 'afterExport';

    /**
     * Stage labels reported through {@see ProgressInterface}. Queue jobs map these onto
     * slices of their progress bar, so keep them in sync with
     * {@see \justinholtweb\transport\queue\ExportJob::stages()}.
     */
    public const STAGE_GATHER = 'Gathering elements';
    public const STAGE_SERIALIZE = 'Serializing elements';
    public const STAGE_FILES = 'Bundling asset files';
    public const STAGE_WRITE = 'Writing package';

    /**
     * Runs an export and returns the absolute path to the written package (empty string
     * if a {@see self::EVENT_BEFORE_EXPORT} listener cancels it).
     */
    public function export(ExportConfig $config): string
    {
        return $this->run($config)->packagePath ?? '';
    }

    /**
     * Runs an export, reporting progress as it goes, and returns a detailed report of
     * what was exported, skipped and failed.
     */
    public function run(ExportConfig $config, ?ProgressInterface $progress = null): TransportReport
    {
        $plugin = Plugin::getInstance();
        $serializer = $plugin->serializer;
        $progress ??= new NullProgress();

        $report = new TransportReport();
        $report->direction = ImportHistory::DIRECTION_EXPORT;
        $report->userId = $config->userId ?? $this->currentUserId();
        $report->begin();

        $beforeEvent = new BeforeExportEvent(['config' => $config]);
        $this->trigger(self::EVENT_BEFORE_EXPORT, $beforeEvent);
        if (!$beforeEvent->isValid) {
            $progress->note('Export cancelled by a beforeExport listener.');
            $report->end(TransportReport::STATUS_CANCELLED);
            return $report;
        }

        $progress->start(self::STAGE_GATHER);
        $elements = $this->gatherElements($config);

        // Only what the person who started the export could view. Before 5.1.1 the export
        // permission read every section, every volume and every user account.
        $actor = Access::actor($report->userId);
        if ($actor !== null) {
            $visible = array_values(array_filter($elements, static fn(ElementInterface $e) => Access::canView($e, $actor)));
            if (count($visible) < count($elements)) {
                $progress->note(sprintf('%d element(s) left out: you can’t view them.', count($elements) - count($visible)));
            }
            $elements = $visible;
        }

        $progress->finish(sprintf('Gathered %d element(s).', count($elements)));

        // Resolve a dependency-safe import order across the whole set.
        $resolution = $plugin->dependencies->resolve($elements);
        if ($resolution['cycles']) {
            $progress->note(sprintf('%d dependency cycle(s) detected — order may need review.', count($resolution['cycles'])));
        }

        $progress->start(self::STAGE_SERIALIZE, count($elements));
        $grouped = [];
        $exported = [];

        foreach ($elements as $element) {
            try {
                $data = $serializer->serializeElement($element);
            } catch (Throwable $e) {
                $report->record(TransportReport::ACTION_FAILED, [
                    'uid' => $element->uid,
                    'type' => $element::class,
                    'key' => $this->keyForElement($element),
                    'sites' => ['' => ['title' => (string)$element->title]],
                ], $e->getMessage());
                $progress->advance(sprintf('%s — failed: %s', $element->title, $e->getMessage()));
                continue;
            }

            $grouped[$data['key']][] = $data;
            $exported[] = $element;
            $report->record(TransportReport::ACTION_CREATED, $data);
            $progress->advance(TransportReport::titleOf($data));
        }

        $progress->finish();

        $progress->start(self::STAGE_FILES);
        $files = $config->includeAssetFiles
            ? $plugin->assets->filesForElements($exported)
            : [];
        $progress->finish(sprintf('%d asset file(s) bundled.', count($files)));

        $progress->start(self::STAGE_WRITE);
        $path = $plugin->packages->write($grouped, $files, $config->packageName, [
            'importOrder' => $resolution['order'],
            'cycles' => $resolution['cycles'],
        ]);

        $report->packagePath = $path;
        $report->packageName = basename($path);

        // A package that was written is a usable export even if individual elements
        // couldn't be serialized — those are reported as failures rather than sinking
        // the whole run.
        $report->end($grouped ? TransportReport::STATUS_COMPLETED : TransportReport::STATUS_FAILED);
        $progress->finish(sprintf('Wrote %s', $path));

        $this->recordHistory($report, $grouped);

        $this->trigger(self::EVENT_AFTER_EXPORT, new AfterExportEvent([
            'config' => $config,
            'path' => $path,
            'elements' => $grouped,
            'report' => $report,
        ]));

        return $report;
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
                } elseif ($config->section && $query instanceof EntryQuery) {
                    $query->section($config->section);
                }
            }

            $elements = array_merge($elements, $query->all());
        }

        return $elements;
    }

    private function keyForElement(ElementInterface $element): string
    {
        return Plugin::getInstance()->elementRegistry
            ->getHandlerForType($element::class)?->packageKey() ?? 'elements';
    }

    /**
     * Writes the export's history row and links the report to it.
     *
     * @param array<string, array> $grouped
     */
    private function recordHistory(TransportReport $report, array $grouped): void
    {
        $counts = [];
        foreach ($grouped as $key => $elements) {
            $counts[$key] = count($elements);
        }

        $record = new ImportHistory();
        $record->packageName = $report->packageName;
        $record->direction = ImportHistory::DIRECTION_EXPORT;
        $record->status = $report->isSuccessful()
            ? ImportHistory::STATUS_COMPLETED
            : ImportHistory::STATUS_FAILED;
        $record->elementCounts = $counts;
        $record->userId = $report->userId;
        $record->setRunReport($report);
        $record->save(false);

        $report->historyId = $record->id;
    }

    /**
     * The logged-in user, when there is one — queue workers and console runs have none,
     * so those pass the initiating user explicitly on the {@see ExportConfig}.
     */
    private function currentUserId(): ?int
    {
        return Craft::$app->getUser()->getId();
    }
}
