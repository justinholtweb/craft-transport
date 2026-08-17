<?php

namespace justinholtweb\transport\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use justinholtweb\transport\events\AfterImportEvent;
use justinholtweb\transport\events\BeforeImportEvent;
use justinholtweb\transport\helpers\IdentityHelper;
use justinholtweb\transport\models\TransportPackage;
use justinholtweb\transport\models\TransportReport;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\progress\NullProgress;
use justinholtweb\transport\progress\ProgressInterface;
use justinholtweb\transport\records\ImportHistory;
use Throwable;
use yii\base\Component;

/**
 * Orchestrates the import flow.
 *
 * Elements are imported in the package's recorded dependency order, each across every
 * site it carries (primary first), wrapped in a transaction. Every element's outcome —
 * created, updated, skipped or failed — is collected into a {@see TransportReport} that
 * is stored on the history row, printed by the console command, and emailed to whoever
 * started a queued import.
 */
class Import extends Component
{
    /** Raised after a package is opened/validated but before importing; may cancel. */
    public const EVENT_BEFORE_IMPORT = 'beforeImport';

    /** Raised after an import finishes. */
    public const EVENT_AFTER_IMPORT = 'afterImport';

    /**
     * Stage labels reported through {@see ProgressInterface}. Queue jobs map these onto
     * slices of their progress bar, so keep them in sync with
     * {@see \justinholtweb\transport\queue\ImportJob::stages()}.
     */
    public const STAGE_READ = 'Reading package';
    public const STAGE_SNAPSHOT = 'Capturing snapshot';
    public const STAGE_IMPORT = 'Importing elements';
    public const STAGE_FINISH = 'Finishing up';

    /**
     * Imports a package from disk.
     *
     * @param string $path Absolute path to the package zip.
     * @param bool $dryRun When true, rolls back after simulating — reports what would change.
     * @param array<string, string> $siteMap Optional source→target site handle mapping.
     * @param array $options Optional 'selectedUids' (string[]|null = all),
     *                       'decisions' (array<uid, string[] rejected paths>) and
     *                       'userId' (who to credit in history).
     * @return array{status:string,created:int,updated:int,skipped:int,failed:int,errors:string[]}
     */
    public function importPackage(string $path, bool $dryRun = false, array $siteMap = [], array $options = []): array
    {
        return $this->run($path, $dryRun, $siteMap, $options)->toLegacyResult();
    }

    /**
     * Imports a package from disk, reporting progress as it goes, and returns a detailed
     * report of every element created, updated, skipped and failed.
     */
    public function run(
        string $path,
        bool $dryRun = false,
        array $siteMap = [],
        array $options = [],
        ?ProgressInterface $progress = null,
    ): TransportReport {
        $plugin = Plugin::getInstance();
        $progress ??= new NullProgress();

        $report = new TransportReport();
        $report->direction = ImportHistory::DIRECTION_IMPORT;
        $report->dryRun = $dryRun;
        $report->packageName = basename($path);
        $report->packagePath = $path;
        $report->userId = $options['userId'] ?? $this->currentUserId();
        $report->begin();

        $progress->start(self::STAGE_READ);
        $package = $plugin->packages->open($path);

        $validationErrors = $plugin->packages->validate($package);
        if ($validationErrors) {
            foreach ($validationErrors as $error) {
                $report->recordError($error);
            }
            $progress->finish('Package failed validation.');
            $report->end(TransportReport::STATUS_FAILED);

            // A queued import that never got started still has to be accounted for, or
            // it would vanish without trace from the History screen.
            if (!$dryRun) {
                $history = $this->startHistory($report);
                $this->finishHistory($history, $report, null);
            }

            return $report;
        }

        $beforeEvent = new BeforeImportEvent(['package' => $package, 'dryRun' => $dryRun]);
        $this->trigger(self::EVENT_BEFORE_IMPORT, $beforeEvent);
        if (!$beforeEvent->isValid) {
            $progress->finish('Import cancelled by a beforeImport listener.');
            $report->end(TransportReport::STATUS_CANCELLED);
            return $report;
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

        $progress->finish(sprintf('%d element(s) selected for import.', count($toImport)));

        // Record real (non-dry-run) imports in history up front so failures are visible.
        $history = $dryRun ? null : $this->startHistory($report);

        $transaction = Craft::$app->getDb()->beginTransaction();
        $snapshot = null;

        try {
            // Capture prior state before mutating anything, so we can roll back.
            $progress->start(self::STAGE_SNAPSHOT);
            // Pass the full payloads, not just uid/type: the snapshotter resolves them
            // the same way the import will, and a natural-key match needs the attributes.
            $snapshotEntries = $dryRun ? [] : $plugin->snapshots->capture($toImport);
            $progress->finish($dryRun ? 'Dry run — no snapshot taken.' : sprintf('%d element(s) snapshotted.', count($snapshotEntries)));

            $progress->start(self::STAGE_IMPORT, count($toImport));

            foreach ($toImport as $data) {
                $this->importElement($package, $data, $siteMap, $report, $decisions[$data['uid'] ?? ''] ?? []);
                $progress->advance(sprintf('%s (%s)', TransportReport::titleOf($data), $data['key'] ?? 'element'));
            }

            $progress->finish();
            $progress->start(self::STAGE_FINISH);

            if ($dryRun || $report->errors) {
                $transaction->rollBack();
            } else {
                $snapshot = $plugin->snapshots->save($history->id, $snapshotEntries);
                $transaction->commit();
            }
        } catch (Throwable $e) {
            $transaction->rollBack();
            $report->recordError($e->getMessage());
        }

        $report->end();
        $progress->finish($report->summary());

        Craft::info(sprintf(
            'Import %s [%s]: %s, errors=%d, %.2fs',
            $report->packageName,
            $report->status,
            $report->summary(),
            count($report->errors),
            $report->duration
        ), 'transport');

        if ($history !== null) {
            $this->finishHistory($history, $report, $snapshot?->id);
        }

        $this->trigger(self::EVENT_AFTER_IMPORT, new AfterImportEvent([
            'package' => $package,
            'result' => $report->toLegacyResult(),
            'report' => $report,
            'dryRun' => $dryRun,
        ]));

        return $report;
    }

    /**
     * Opens the import's history row in the `running` state, so a job that dies mid-way
     * is still visible on the History screen.
     */
    private function startHistory(TransportReport $report): ImportHistory
    {
        $history = new ImportHistory();
        $history->packageName = $report->packageName ?: 'package.zip';
        $history->direction = ImportHistory::DIRECTION_IMPORT;
        $history->status = ImportHistory::STATUS_RUNNING;
        $history->userId = $report->userId;
        $history->save(false);

        $report->historyId = $history->id;

        return $history;
    }

    /**
     * Settles the history row with the finished report.
     */
    private function finishHistory(ImportHistory $history, TransportReport $report, ?int $snapshotId): void
    {
        $history->status = $report->errors ? ImportHistory::STATUS_FAILED : ImportHistory::STATUS_COMPLETED;
        $history->elementCounts = $report->getCounts();
        $history->errorLog = $report->errors ? json_encode($report->errors) : null;
        $history->snapshotId = $snapshotId;
        $history->setRunReport($report);
        $history->save(false);
    }

    /**
     * Imports one element across all of its sites, recording its outcome on the report.
     *
     * @param string[] $rejectedPaths Field paths to keep at the target's current value.
     */
    private function importElement(
        TransportPackage $package,
        array $data,
        array $siteMap,
        TransportReport $report,
        array $rejectedPaths = [],
    ): void {
        $plugin = Plugin::getInstance();
        $elementsService = Craft::$app->getElements();
        IdentityHelper::flush();

        // The element this payload belongs to here: the one carrying its UID, or the one
        // its handler recognises as the same content under a different UID.
        $existing = IdentityHelper::resolveImportTarget($data);
        $isUpdate = $existing !== null;
        $adopted = IdentityHelper::isNaturalKeyMatch($data, $existing);

        // Every site of a multi-site element writes to this one element.
        $targetId = $existing?->id;

        // Apply field-level merge decisions before normalizing.
        if ($rejectedPaths) {
            $current = $existing ? $plugin->serializer->serializeElement($existing) : null;
            $data = $plugin->merger->apply($data, $rejectedPaths, $current);
        }

        $savedAny = false;

        foreach ($plugin->normalizer->orderedSiteHandles($data) as $sourceHandle) {
            $element = $plugin->normalizer->normalizeElementForSite($data, $sourceHandle, $siteMap, $targetId);
            if ($element === null) {
                continue;
            }

            // New assets need their bundled file staged before saving.
            if ($element instanceof Asset && !$plugin->assets->stage($package, $data, $element)) {
                continue;
            }

            if (!$elementsService->saveElement($element)) {
                $detail = implode('; ', $element->getFirstErrors());
                $message = sprintf(
                    '%s "%s" [%s]: %s',
                    $data['type'] ?? 'element',
                    $data['sites'][$sourceHandle]['title'] ?? ($data['uid'] ?? '?'),
                    $sourceHandle,
                    $detail
                );

                $report->record(TransportReport::ACTION_FAILED, $data, $message);
                $report->recordError($message);
                return;
            }

            $targetId ??= $element->id;
            $savedAny = true;
        }

        if (!$savedAny) {
            $report->record(
                TransportReport::ACTION_SKIPPED,
                $data,
                'No site on this element could be mapped to a site in this environment.'
            );
            return;
        }

        $report->record(
            $isUpdate ? TransportReport::ACTION_UPDATED : TransportReport::ACTION_CREATED,
            $data,
            $adopted ? $this->adoptionNote($existing) : ''
        );
    }

    /**
     * Explains that an element already here — under a different UID — was updated
     * rather than duplicated, so the report shows what the import adopted.
     */
    private function adoptionNote(ElementInterface $existing): string
    {
        Craft::info(sprintf(
            'Matched existing %s #%d (uid %s) by its attributes; updating rather than creating a duplicate.',
            $existing::class,
            $existing->id,
            $existing->uid
        ), 'transport');

        return sprintf(
            'Matched the existing "%s" (#%d), which carries a different UID; updated it instead of creating a duplicate.',
            $existing->getUiLabel(),
            $existing->id
        );
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

    /**
     * The logged-in user, when there is one — queue workers and console runs have none,
     * so those pass the initiating user in the import options.
     */
    private function currentUserId(): ?int
    {
        return Craft::$app->getUser()->getId();
    }
}
