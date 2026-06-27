<?php

namespace justinholtweb\transport\services;

use yii\base\Component;

/**
 * Applies a user's per-field accept/reject decisions to an incoming element payload.
 *
 * A "rejected" field keeps the target's current value (so importing it is a no-op);
 * when the element is new, a rejected field is simply dropped so it isn't set.
 *
 * Decisions are expressed as a list of "<siteHandle>.<field>" paths, where <field> is
 * a custom field handle or one of the pseudo-fields "title"/"slug".
 */
class Merger extends Component
{
    /**
     * @param array $incoming Serialized element payload (multi-site format).
     * @param string[] $rejectedPaths Paths to keep at the target's current value.
     * @param array|null $current Serialized current target element, if it exists.
     * @return array The merged payload to import.
     */
    public function apply(array $incoming, array $rejectedPaths, ?array $current): array
    {
        foreach ($rejectedPaths as $path) {
            [$site, $field] = array_pad(explode('.', $path, 2), 2, null);
            if ($site === null || $field === null) {
                continue;
            }

            $currentValue = $this->currentValue($current, $site, $field);

            if ($field === 'title' || $field === 'slug') {
                if ($currentValue === null) {
                    unset($incoming['sites'][$site][$field]);
                } else {
                    $incoming['sites'][$site][$field] = $currentValue;
                }
                continue;
            }

            if ($currentValue === null) {
                unset($incoming['sites'][$site]['fields'][$field]);
            } else {
                $incoming['sites'][$site]['fields'][$field] = $currentValue;
            }
        }

        return $incoming;
    }

    private function currentValue(?array $current, string $site, string $field): mixed
    {
        if ($current === null) {
            return null;
        }

        if ($field === 'title' || $field === 'slug') {
            return $current['sites'][$site][$field] ?? null;
        }

        return $current['sites'][$site]['fields'][$field] ?? null;
    }
}
