<?php

namespace justinholtweb\transport\services;

use Craft;
use craft\elements\User;
use craft\helpers\Json;
use justinholtweb\transport\helpers\Access;
use justinholtweb\transport\helpers\IdentityHelper;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\records\ElementSnapshot;
use justinholtweb\transport\records\ImportHistory;
use Throwable;
use yii\base\Component;

/**
 * Captures the pre-import state of affected elements and restores it on rollback.
 *
 * A snapshot records, per affected element, either its full prior serialized state
 * (so an update can be reverted) or a flag that it did not previously exist (so a
 * created element can be deleted on rollback).
 */
class Snapshotter extends Component
{
    /**
     * Captures the current state of the given elements so it can be restored later.
     * Reads the live DB, so call before any mutation.
     *
     * Elements are resolved exactly the way the import will resolve them — including a
     * handler's natural-key match — so an element the import is about to adopt is
     * snapshotted as an update to restore, not as something to delete on rollback. The
     * entry records the resolved element's own UID for the same reason.
     *
     * @param array<int, array<string, mixed>> $refs Serialized elements, or at
     *        minimum their uid and type.
     * @return array<int, array> Snapshot entries.
     */
    public function capture(array $refs): array
    {
        $serializer = Plugin::getInstance()->serializer;
        $entries = [];

        foreach ($refs as $ref) {
            $uid = $ref['uid'] ?? null;
            $type = $ref['type'] ?? null;
            if (!$uid || !$type) {
                continue;
            }

            $existing = IdentityHelper::resolveImportTarget($ref);
            $entries[] = $existing
                ? ['uid' => $existing->uid, 'type' => $type, 'existed' => true, 'data' => $serializer->serializeElement($existing)]
                : ['uid' => $uid, 'type' => $type, 'existed' => false];
        }

        return $entries;
    }

    /**
     * Persists captured entries as a snapshot row linked to a history record.
     */
    public function save(int $historyId, array $entries): ElementSnapshot
    {
        $record = new ElementSnapshot();
        $record->historyId = $historyId;
        $record->elementData = $this->compress($entries);
        $record->save(false);

        return $record;
    }

    /**
     * Rolls an import back: restores updated elements to their prior state and deletes
     * elements the import created. The rollback is itself snapshot-protected, so it can
     * be undone.
     *
     * @return array{status:string,restored:int,deleted:int,errors:string[]}
     */
    public function rollback(ImportHistory $history): array
    {
        $snapshot = ElementSnapshot::findOne(['id' => $history->snapshotId]);
        if (!$snapshot) {
            return ['status' => 'failed', 'restored' => 0, 'deleted' => 0, 'errors' => ['No snapshot to roll back to.']];
        }

        $entries = $this->decompress($snapshot->elementData);

        // All or nothing: a rollback that could restore only some of what it touched would leave
        // content half-migrated. So check every element against the person's own permissions
        // first — restoring needs save rights, removing what the import created needs delete.
        $refused = $this->refusedForRollback($entries, Access::actor(Craft::$app->getUser()->getId()));
        if ($refused) {
            return ['status' => 'failed', 'restored' => 0, 'deleted' => 0, 'errors' => [
                sprintf('You don’t have permission to roll back %d of these elements: %s', count($refused), implode(', ', array_slice($refused, 0, 5))),
            ]];
        }

        $refs = array_map(static fn($e) => ['uid' => $e['uid'], 'type' => $e['type']], $entries);

        // Record the rollback as its own history op, snapshot-protected for undo.
        $rollbackHistory = new ImportHistory();
        $rollbackHistory->packageName = 'Rollback of #' . $history->id;
        $rollbackHistory->direction = ImportHistory::DIRECTION_IMPORT;
        $rollbackHistory->status = ImportHistory::STATUS_RUNNING;
        $rollbackHistory->userId = Craft::$app->getUser()->getId();
        $rollbackHistory->save(false);

        $result = ['status' => 'running', 'restored' => 0, 'deleted' => 0, 'errors' => []];
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            // Undo snapshot: current (post-import) state of the same elements.
            IdentityHelper::flush();
            $undoEntries = $this->capture($refs);

            $this->applyEntries($entries, $result);

            $undoSnapshot = $this->save($rollbackHistory->id, $undoEntries);
            $transaction->commit();
            $rollbackHistory->snapshotId = $undoSnapshot->id;
        } catch (Throwable $e) {
            $transaction->rollBack();
            $result['errors'][] = $e->getMessage();
        }

        $result['status'] = $result['errors'] ? 'failed' : 'completed';

        $rollbackHistory->status = $result['errors'] ? ImportHistory::STATUS_FAILED : ImportHistory::STATUS_COMPLETED;
        $rollbackHistory->elementCounts = ['restored' => $result['restored'], 'deleted' => $result['deleted']];
        $rollbackHistory->errorLog = $result['errors'] ? Json::encode($result['errors']) : null;
        $rollbackHistory->save(false);

        if (!$result['errors']) {
            $history->status = ImportHistory::STATUS_ROLLED_BACK;
            $history->save(false);
        }

        return $result;
    }

    /**
     * Restores updated elements then deletes created ones (in reverse order).
     */
    private function applyEntries(array $entries, array &$result): void
    {
        $normalizer = Plugin::getInstance()->normalizer;
        $elementsService = Craft::$app->getElements();

        // Restore prior state for elements that existed before the import.
        foreach ($entries as $entry) {
            if (empty($entry['existed'])) {
                continue;
            }
            IdentityHelper::flush();
            $restoredThis = false;
            foreach ($normalizer->orderedSiteHandles($entry['data']) as $siteHandle) {
                $element = $normalizer->normalizeElementForSite($entry['data'], $siteHandle);
                if ($element && $elementsService->saveElement($element)) {
                    $restoredThis = true;
                }
            }
            if ($restoredThis) {
                $result['restored']++;
            }
        }

        // Delete elements the import created — reverse order so dependents go first.
        foreach (array_reverse($entries) as $entry) {
            if (!empty($entry['existed'])) {
                continue;
            }
            IdentityHelper::flush();
            $element = IdentityHelper::resolveElement($entry['uid'], $entry['type']);
            if ($element && $elementsService->deleteElement($element, true)) {
                $result['deleted']++;
            }
        }
    }

    /**
     * Labels of the elements in a snapshot that `$user` couldn't restore or delete.
     *
     * @return string[]
     */
    private function refusedForRollback(array $entries, ?User $user): array
    {
        if ($user === null || $user->admin) {
            return [];
        }

        $refused = [];
        foreach ($entries as $entry) {
            IdentityHelper::flush();
            $element = IdentityHelper::resolveElement($entry['uid'] ?? '', $entry['type'] ?? '');
            if ($element === null) {
                continue;
            }

            $allowed = !empty($entry['existed']) ? Access::canSave($element, $user) : Access::canDelete($element, $user);
            if (!$allowed) {
                $refused[] = sprintf('"%s"', $element->getUiLabel());
            }
        }

        return $refused;
    }

    private function compress(array $entries): string
    {
        return base64_encode(gzcompress(Json::encode($entries), 9));
    }

    private function decompress(?string $data): array
    {
        if (!$data) {
            return [];
        }
        $json = @gzuncompress(base64_decode($data));
        return $json ? (Json::decode($json) ?: []) : [];
    }
}
