<?php

namespace justinholtweb\transport\services;

use Craft;
use justinholtweb\transport\elements\AssetHandler;
use justinholtweb\transport\models\TransportPackage;
use justinholtweb\transport\Plugin;
use yii\base\Component;

/**
 * Pre-flight checks run before an import: confirms the target has the schema the
 * package needs (sections, entry types, groups, volumes), that referenced sites exist,
 * and that there is room for any bundled asset files.
 */
class ValidationService extends Component
{
    /**
     * @return array{errors:string[],warnings:string[]}
     */
    public function validate(TransportPackage $package): array
    {
        $errors = [];
        $warnings = [];

        $missingSections = [];
        $missingEntryTypes = [];
        $missingGroups = [];
        $missingGlobals = [];
        $missingVolumes = [];
        $missingSites = [];

        $sites = Craft::$app->getSites();
        $entriesSvc = Craft::$app->getEntries();

        foreach ($package->allElements() as $data) {
            $attr = $data['attributes'] ?? [];

            // Sites referenced by the element must exist in the target.
            foreach (array_keys($data['sites'] ?? []) as $siteHandle) {
                if (!$sites->getSiteByHandle($siteHandle)) {
                    $missingSites[$siteHandle] = true;
                }
            }

            switch ($data['key'] ?? '') {
                case 'entries':
                    $section = $attr['section'] ?? null;
                    if ($section && !$entriesSvc->getSectionByHandle($section)) {
                        $missingSections[$section] = true;
                    } elseif ($section && ($attr['type'] ?? null)) {
                        $hasType = false;
                        foreach ($entriesSvc->getSectionByHandle($section)->getEntryTypes() as $et) {
                            if ($et->handle === $attr['type']) {
                                $hasType = true;
                                break;
                            }
                        }
                        if (!$hasType) {
                            $missingEntryTypes["$section/{$attr['type']}"] = true;
                        }
                    }
                    break;
                case 'categories':
                    if (($attr['group'] ?? null) && !Craft::$app->getCategories()->getGroupByHandle($attr['group'])) {
                        $missingGroups["category:{$attr['group']}"] = true;
                    }
                    break;
                case 'tags':
                    if (($attr['group'] ?? null) && !Craft::$app->getTags()->getTagGroupByHandle($attr['group'])) {
                        $missingGroups["tag:{$attr['group']}"] = true;
                    }
                    break;
                case 'globals':
                    if (($attr['handle'] ?? null) && !Craft::$app->getGlobals()->getSetByHandle($attr['handle'])) {
                        $missingGlobals[$attr['handle']] = true;
                    }
                    break;
                case 'assets':
                    if (($attr['volume'] ?? null) && !Craft::$app->getVolumes()->getVolumeByHandle($attr['volume'])) {
                        $missingVolumes[$attr['volume']] = true;
                    }
                    break;
            }
        }

        foreach ($missingSections as $h => $_) {
            $errors[] = Craft::t('transport', 'Missing section: {handle}', ['handle' => $h]);
        }
        foreach ($missingEntryTypes as $h => $_) {
            $errors[] = Craft::t('transport', 'Missing entry type: {handle}', ['handle' => $h]);
        }
        foreach ($missingGroups as $h => $_) {
            $errors[] = Craft::t('transport', 'Missing group: {handle}', ['handle' => $h]);
        }
        foreach ($missingVolumes as $h => $_) {
            $errors[] = Craft::t('transport', 'Missing volume: {handle}', ['handle' => $h]);
        }
        foreach ($missingSites as $h => $_) {
            $warnings[] = Craft::t('transport', 'Site “{handle}” does not exist in this environment and will be skipped.', ['handle' => $h]);
        }
        foreach ($missingGlobals as $h => $_) {
            $warnings[] = Craft::t('transport', 'Global set “{handle}” does not exist and will be skipped.', ['handle' => $h]);
        }

        // Disk space for bundled asset files (best effort).
        $assetBytes = $this->bundledAssetBytes($package);
        if ($assetBytes > 0) {
            $free = @disk_free_space(Plugin::getInstance()->getSettings()->getResolvedTempPath());
            if ($free !== false && $free < $assetBytes * 2) {
                $warnings[] = Craft::t('transport', 'Low disk space for staging asset files ({mb} MB needed).', [
                    'mb' => round($assetBytes / 1048576, 1),
                ]);
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function bundledAssetBytes(TransportPackage $package): int
    {
        $total = 0;
        foreach ($package->getElementsByKey('assets') as $asset) {
            $bytes = $asset['attributes']['size'] ?? 0;
            $total += (int)$bytes;
        }
        return $total;
    }
}
