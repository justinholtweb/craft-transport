<?php

namespace justinholtweb\transport\services;

use Craft;
use craft\elements\Asset;
use justinholtweb\transport\helpers\IdentityHelper;
use justinholtweb\transport\models\TransportPackage;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\records\ImportHistory;
use Throwable;
use yii\base\Component;

/**
 * Orchestrates the import flow.
 *
 * Elements are imported in the package's recorded dependency order, each across every
 * site it carries (primary first), wrapped in a transaction. Diff/preview and selective
 * merge (Phase 3) and snapshotting/rollback (Phase 4) build on top of this.
 */
class Import extends Component
{
    /**
     * Imports a package from disk.
     *
     * @param string $path Absolute path to the package zip.
     * @param bool $dryRun When true, rolls back after simulating — reports what would change.
     * @param array<string, string> $siteMap Optional source→target site handle mapping.
     * @param array $options Optional 'selectedUids' (string[]|null = all) and
     *                       'decisions' (array<uid, string[] rejected paths>).
     * @return array{status:string,created:int,updated:int,skipped:int,errors:string[]}
     */
    public function importPackage(string $path, bool $dryRun = false, array $siteMap = [], array $options = []): array
    {
        $plugin = Plugin::getInstance();
        $package = $plugin->packages->open($path);

        $validationErrors = $plugin->packages->validate($package);
        if ($validationErrors) {
            return [
                'status' => 'failed',
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => $validationErrors,
            ];
        }

        $selectedUids = $options['selectedUids'] ?? null;
        $decisions = $options['decisions'] ?? [];

        // The ordered set of elements we will actually touch.
        $toImport = [];
        foreach ($this->orderedElements($package) as $data) {
            if ($selectedUids === null || in_array($data['uid'] ?? '', $selectedUids, true)) {
                $toImport[] = $data;
            }
        }

        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        // Record real (non-dry-run) imports in history up front so failures are visible.
        $history = null;
        if (!$dryRun) {
            $history = new ImportHistory();
            $history->packageName = $package->path ? basename($package->path) : 'package.zip';
            $history->direction = ImportHistory::DIRECTION_IMPORT;
            $history->status = ImportHistory::STATUS_RUNNING;
            $history->userId = Craft::$app->getUser()->getId();
            $history->save(false);
        }

        $transaction = Craft::$app->getDb()->beginTransaction();
        $snapshot = null;

        try {
            // Capture prior state before mutating anything, so we can roll back.
            $snapshotEntries = $dryRun ? [] : $plugin->snapshots->capture(
                array_map(static fn($d) => ['uid' => $d['uid'] ?? '', 'type' => $d['type'] ?? ''], $toImport)
            );

            foreach ($toImport as $data) {
                $this->importElement($package, $data, $siteMap, $result, $decisions[$data['uid'] ?? ''] ?? []);
            }

            if ($dryRun || $result['errors']) {
                $transaction->rollBack();
            } else {
                $snapshot = $plugin->snapshots->save($history->id, $snapshotEntries);
                $transaction->commit();
            }
        } catch (Throwable $e) {
            $transaction->rollBack();
            $result['errors'][] = $e->getMessage();
        }

        $result['status'] = $result['errors'] ? 'failed' : ($dryRun ? 'dry-run' : 'completed');

        Craft::info(sprintf(
            'Import %s [%s]: created=%d updated=%d skipped=%d errors=%d',
            $package->path ? basename($package->path) : 'package',
            $result['status'],
            $result['created'],
            $result['updated'],
            $result['skipped'],
            count($result['errors'])
        ), 'transport');

        if ($history !== null) {
            $history->status = $result['errors'] ? ImportHistory::STATUS_FAILED : ImportHistory::STATUS_COMPLETED;
            $history->elementCounts = [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
            ];
            $history->errorLog = $result['errors'] ? json_encode($result['errors']) : null;
            $history->snapshotId = $snapshot?->id;
            $history->save(false);
        }

        return $result;
    }

    /**
     * Imports one element across all of its sites, updating $result counters.
     *
     * @param string[] $rejectedPaths Field paths to keep at the target's current value.
     */
    private function importElement(TransportPackage $package, array $data, array $siteMap, array &$result, array $rejectedPaths = []): void
    {
        $plugin = Plugin::getInstance();
        $elementsService = Craft::$app->getElements();
        IdentityHelper::flush();

        $existing = IdentityHelper::resolveElement($data['uid'] ?? '', $data['type'] ?? '');
        $isUpdate = $existing !== null;

        // Apply field-level merge decisions before normalizing.
        if ($rejectedPaths) {
            $current = $existing ? $plugin->serializer->serializeElement($existing) : null;
            $data = $plugin->merger->apply($data, $rejectedPaths, $current);
        }

        $savedAny = false;

        foreach ($plugin->normalizer->orderedSiteHandles($data) as $sourceHandle) {
            $element = $plugin->normalizer->normalizeElementForSite($data, $sourceHandle, $siteMap);
            if ($element === null) {
                continue;
            }

            // New assets need their bundled file staged before saving.
            if ($element instanceof Asset && !$plugin->assets->stage($package, $data, $element)) {
                continue;
            }

            if (!$elementsService->saveElement($element)) {
                $result['errors'][] = sprintf(
                    '%s "%s" [%s]: %s',
                    $data['type'] ?? 'element',
                    $data['sites'][$sourceHandle]['title'] ?? ($data['uid'] ?? '?'),
                    $sourceHandle,
                    implode('; ', $element->getFirstErrors())
                );
                return;
            }

            $savedAny = true;
        }

        if (!$savedAny) {
            $result['skipped']++;
            return;
        }

        $isUpdate ? $result['updated']++ : $result['created']++;
    }

    /**
     * Returns serialized elements ordered by the package's dependency order, with any
     * elements missing from that order appended at the end.
     *
     * @return array<int, array>
     */
    private function orderedElements(TransportPackage $package): array
    {
        $byUid = [];
        foreach ($package->allElements() as $data) {
            if (isset($data['uid'])) {
                $byUid[$data['uid']] = $data;
            }
        }

        $ordered = [];
        foreach ($package->getImportOrder() as $uid) {
            if (isset($byUid[$uid])) {
                $ordered[] = $byUid[$uid];
                unset($byUid[$uid]);
            }
        }

        // Anything not covered by importOrder (older packages, etc.) goes last.
        foreach ($byUid as $data) {
            $ordered[] = $data;
        }

        return $ordered;
    }
}
