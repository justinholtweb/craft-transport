<?php

namespace justinholtweb\transport\services;

use Craft;
use craft\helpers\Json;
use justinholtweb\transport\helpers\IdentityHelper;
use justinholtweb\transport\models\DiffEntry;
use justinholtweb\transport\models\DiffResult;
use justinholtweb\transport\models\TransportPackage;
use justinholtweb\transport\Plugin;
use yii\base\Component;

/**
 * Field-level diff engine.
 *
 * Compares each package element against its target counterpart by serializing the
 * existing element with the same serializer and comparing per-site, per-field.
 */
class Differ extends Component
{
    /**
     * @return DiffResult[] One per element in the package, keyed numerically.
     */
    public function diffPackage(TransportPackage $package): array
    {
        $results = [];
        foreach ($package->allElements() as $data) {
            $results[] = $this->diffElement($data);
        }
        return $results;
    }

    /**
     * Diffs a single serialized element against the target environment.
     */
    public function diffElement(array $incoming): DiffResult
    {
        $result = new DiffResult([
            'uid' => $incoming['uid'] ?? '',
            'type' => $incoming['type'] ?? '',
            'key' => $incoming['key'] ?? 'elements',
            'title' => $this->primaryTitle($incoming),
        ]);

        $existing = IdentityHelper::resolveElement($result->uid, $result->type);

        if ($existing === null) {
            $result->exists = false;
            $result->action = DiffResult::ACTION_ADD;
            $result->entries = $this->entriesForAdd($incoming);
            return $result;
        }

        $result->exists = true;
        $current = Plugin::getInstance()->serializer->serializeElement($existing);
        $result->entries = $this->compareSites($incoming, $current);
        $result->action = $result->changeCount() > 0
            ? DiffResult::ACTION_UPDATE
            : DiffResult::ACTION_UNCHANGED;

        return $result;
    }

    /**
     * @return DiffEntry[]
     */
    private function compareSites(array $incoming, array $current): array
    {
        $entries = [];
        $siteHandles = array_unique(array_merge(
            array_keys($incoming['sites'] ?? []),
            array_keys($current['sites'] ?? [])
        ));

        foreach ($siteHandles as $site) {
            $in = $incoming['sites'][$site] ?? null;
            $cur = $current['sites'][$site] ?? null;

            // Pseudo-fields: title and slug.
            foreach (['title', 'slug'] as $pseudo) {
                $entries[] = $this->makeEntry(
                    $site,
                    $pseudo,
                    $cur[$pseudo] ?? null,
                    $in[$pseudo] ?? null
                );
            }

            $fieldHandles = array_unique(array_merge(
                array_keys($in['fields'] ?? []),
                array_keys($cur['fields'] ?? [])
            ));

            foreach ($fieldHandles as $handle) {
                $entries[] = $this->makeEntry(
                    $site,
                    $handle,
                    $cur['fields'][$handle] ?? null,
                    $in['fields'][$handle] ?? null
                );
            }
        }

        return $entries;
    }

    /**
     * @return DiffEntry[]
     */
    private function entriesForAdd(array $incoming): array
    {
        $entries = [];
        foreach ($incoming['sites'] ?? [] as $site => $siteData) {
            foreach (['title', 'slug'] as $pseudo) {
                if (($siteData[$pseudo] ?? null) !== null) {
                    $entries[] = $this->makeEntry($site, $pseudo, null, $siteData[$pseudo]);
                }
            }
            foreach ($siteData['fields'] ?? [] as $handle => $value) {
                $entries[] = $this->makeEntry($site, $handle, null, $value);
            }
        }
        return $entries;
    }

    private function makeEntry(string $site, string $field, mixed $old, mixed $new): DiffEntry
    {
        $entry = new DiffEntry([
            'site' => $site,
            'field' => $field,
            'oldValue' => $old,
            'newValue' => $new,
            'oldDisplay' => $this->render($old),
            'newDisplay' => $this->render($new),
        ]);

        $hasOld = $old !== null;
        $hasNew = $new !== null;

        if (!$hasOld && $hasNew) {
            $entry->status = DiffEntry::STATUS_ADDED;
        } elseif ($hasOld && !$hasNew) {
            $entry->status = DiffEntry::STATUS_REMOVED;
        } elseif ($this->encode($old) !== $this->encode($new)) {
            $entry->status = DiffEntry::STATUS_CHANGED;
        } else {
            $entry->status = DiffEntry::STATUS_UNCHANGED;
        }

        return $entry;
    }

    private function encode(mixed $value): string
    {
        return Json::encode($value);
    }

    /**
     * Renders a serialized value as a human-readable string for the diff UI.
     */
    private function render(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            // Relation references: list of {uid, type}.
            if ($this->isRelationRefs($value)) {
                $titles = [];
                foreach ($value as $ref) {
                    $el = IdentityHelper::resolveElement($ref['uid'], $ref['type']);
                    $titles[] = $el?->getUiLabel() ?? ('#' . substr($ref['uid'], 0, 8));
                }
                return $titles ? implode(', ', $titles) : '(none)';
            }

            // Matrix-style block list.
            if (isset($value[0]) && is_array($value[0]) && isset($value[0]['type'])) {
                return count($value) . ' block(s)';
            }

            return Json::encode($value);
        }

        return (string)$value;
    }

    private function isRelationRefs(mixed $value): bool
    {
        if (!is_array($value) || $value === []) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_array($item) || !isset($item['uid'], $item['type'])) {
                return false;
            }
        }
        return true;
    }

    private function primaryTitle(array $incoming): string
    {
        $primary = Craft::$app->getSites()->getPrimarySite()->handle;
        $sites = $incoming['sites'] ?? [];
        $siteData = $sites[$primary] ?? reset($sites) ?: [];
        return $siteData['title'] ?? ($incoming['uid'] ?? '');
    }
}
