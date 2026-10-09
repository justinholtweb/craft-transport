<?php
/**
 * Transport's console commands — each CP bulk action's CLI equivalent — run for real in the
 * plugin-testing harness: export by ID and with dependencies, the import diff, selective import
 * with kept local values, the pre-flight block, rollback dry runs and non-interactive rollbacks,
 * and history view/download. Exit codes are part of the contract, so every check asserts one.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-transport/tests/harness/console.php
 *
 * Self-cleaning: probe entries, history rows, snapshots and packages it creates are removed.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\Entry;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\records\ElementSnapshot;
use justinholtweb\transport\records\ImportHistory;
use yii\console\ExitCode;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

/**
 * Runs `php craft <args>` in a fresh process and returns [exit code, combined output].
 *
 * @return array{0:int,1:string}
 */
function craft(string $args): array
{
    $output = [];
    exec('php craft ' . $args . ' 2>&1', $output, $code);
    return [$code, implode("\n", $output)];
}

function expectExit(array $run, int $code, ?string $contains = null): bool|string
{
    [$actual, $output] = $run;
    if ($actual !== $code) {
        return "exit $actual, expected $code:\n" . substr($output, 0, 1500);
    }
    if ($contains !== null && !str_contains($output, $contains)) {
        return "output lacks \"$contains\":\n" . substr($output, 0, 1500);
    }
    return true;
}

function packageUids(string $path, string $key): array
{
    $package = Plugin::getInstance()->packages->open($path);
    return array_column($package->getElementsByKey($key), 'uid');
}

function freshEntry(int $id): ?Entry
{
    Craft::$app->getElements()->invalidateAllCaches();
    return Entry::find()->id($id)->status(null)->one();
}

Craft::$app->getPlugins()->loadPlugins();

$transport = Plugin::getInstance();
if ($transport === null) {
    echo "Transport isn't installed in the harness.\n";
    exit(1);
}

$tmp = sys_get_temp_dir() . '/transport-console-' . bin2hex(random_bytes(4));
mkdir($tmp);
$startHistoryId = (int)(new Query())->from('{{%transport_history}}')->max('id');
$probeIds = [];

$section = Craft::$app->getEntries()->getSectionByHandle('reminderTestSection');
if (!$section) {
    echo "The harness's reminderTestSection channel is missing.\n";
    exit(1);
}
$site = Craft::$app->getSites()->getPrimarySite()->handle;

// One canonical entry that relates to another element Transport can export.
$related = (new Query())
    ->select(['r.sourceId', 'r.targetId'])
    ->from(['r' => '{{%relations}}'])
    ->innerJoin(['s' => '{{%elements}}'], '[[s.id]] = [[r.sourceId]]')
    ->innerJoin(['t' => '{{%elements}}'], '[[t.id]] = [[r.targetId]]')
    ->innerJoin(['e' => '{{%entries}}'], '[[e.id]] = [[r.sourceId]]')
    ->where([
        's.draftId' => null, 's.revisionId' => null, 's.dateDeleted' => null, 't.dateDeleted' => null,
        't.draftId' => null, 't.revisionId' => null,
        't.type' => [craft\elements\Category::class, craft\elements\Asset::class],
    ])
    ->andWhere(['not', ['e.sectionId' => null]])
    ->one();

try {
    echo "Export\n";

    $source = $related ? Entry::find()->id($related['sourceId'])->status(null)->site('*')->unique()->one() : null;
    $targetUid = $related ? (new Query())->select('uid')->from('{{%elements}}')->where(['id' => $related['targetId']])->scalar() : null;

    check('--ids exports exactly the chosen element', function() use ($source, $tmp) {
        if (!$source) {
            return 'no related entry in the harness to export';
        }
        $run = craft("transport/export --ids={$source->id} --types=entries --output=$tmp/ids.zip --quiet");
        if (($r = expectExit($run, ExitCode::OK, 'Wrote package')) !== true) {
            return $r;
        }
        return packageUids("$tmp/ids.zip", 'entries') === [$source->uid] ?: 'package holds ' . json_encode(packageUids("$tmp/ids.zip", 'entries'));
    });

    check('--ids without --types finds the element whatever its type', function() use ($related, $targetUid, $tmp) {
        $run = craft("transport/export --ids={$related['targetId']} --output=$tmp/any.zip --quiet");
        if (($r = expectExit($run, ExitCode::OK)) !== true) {
            return $r;
        }
        $package = Plugin::getInstance()->packages->open("$tmp/any.zip");
        $uids = array_column($package->allElements(), 'uid');
        return $uids === [$targetUid] ?: 'package holds ' . json_encode($uids);
    });

    check('without --with-dependencies the related element stays out', function() use ($targetUid, $tmp) {
        $package = Plugin::getInstance()->packages->open("$tmp/ids.zip");
        return !in_array($targetUid, array_column($package->allElements(), 'uid'), true);
    });

    check('--with-dependencies pulls in what the entry relates to', function() use ($source, $targetUid, $tmp) {
        $run = craft("transport/export --ids={$source->id} --with-dependencies --output=$tmp/deps.zip --quiet");
        if (($r = expectExit($run, ExitCode::OK)) !== true) {
            return $r;
        }
        $package = Plugin::getInstance()->packages->open("$tmp/deps.zip");
        $uids = array_column($package->allElements(), 'uid');
        if (!in_array($source->uid, $uids, true) || !in_array($targetUid, $uids, true)) {
            return 'package holds ' . json_encode($uids);
        }
        // …and orders the dependency before the entry that needs it.
        $order = $package->getImportOrder();
        return array_search($targetUid, $order, true) < array_search($source->uid, $order, true) ?: 'dependency sorted after its dependent';
    });

    check('an unknown element type is a usage error', fn() => expectExit(
        craft('transport/export --types=entries,widgets --quiet'), ExitCode::USAGE, 'Unknown element type(s): widgets'));
    check('a non-numeric ID is a usage error', fn() => expectExit(
        craft('transport/export --ids=12,abc --quiet'), ExitCode::USAGE, 'Not an element ID: abc'));
    check('a path-like package name is refused, as in the CP', fn() => expectExit(
        craft('transport/export --ids=1 --package-name=../../web/x --quiet'), ExitCode::USAGE, '--packageName'));
    check('an unknown site is a usage error', fn() => expectExit(
        craft('transport/export --site=nowhere --quiet'), ExitCode::USAGE, 'No site with the handle'));
    check('an unwritable --output exits non-zero', fn() => expectExit(
        craft("transport/export --ids={$source->id} --types=entries --output=/proc/nope/x.zip --quiet"), 73));

    echo "Import diff and selective import\n";

    $probe = new Entry();
    $probe->sectionId = $section->id;
    $probe->typeId = $section->getEntryTypes()[0]->id;
    $probe->title = 'Transport console probe';
    $probe->slug = 'transport-console-probe-' . bin2hex(random_bytes(3));
    if (!Craft::$app->getElements()->saveElement($probe)) {
        throw new RuntimeException('Probe entry: ' . implode('; ', $probe->getFirstErrors()));
    }
    $probeIds[] = $probe->id;

    [$code, $out] = craft("transport/export --ids={$probe->id} --types=entries --package-name=console-probe --output=$tmp/probe.zip --quiet");
    if ($code !== 0) {
        throw new RuntimeException("Probe export failed:\n$out");
    }
    $exportHistoryId = (int)ImportHistory::find()->where(['direction' => 'export'])->max('id');

    check('diff of an unchanged package says so and exits 0', fn() => expectExit(
        craft("transport/import/diff $tmp/probe.zip"), ExitCode::OK, '0 to add, 0 to update, 1 unchanged'));

    $probe->title = 'Local edit';
    Craft::$app->getElements()->saveElement($probe);

    check('diff --verbose shows the change and its --keep-local path', fn() => expectExit(
        craft("transport/import/diff $tmp/probe.zip --verbose"), ExitCode::OK, "$site.title: Local edit → Transport console probe"));

    check('--keep-local keeps the local value', function() use ($tmp, $probe, $site) {
        $run = craft("transport/import $tmp/probe.zip --only={$probe->uid} --keep-local={$probe->uid}:$site.title --quiet");
        if (($r = expectExit($run, ExitCode::OK)) !== true) {
            return $r;
        }
        return freshEntry($probe->id)->title === 'Local edit' ?: 'title is now ' . freshEntry($probe->id)->title;
    });

    check('--only leaves unselected elements alone', function() use ($tmp, $probe) {
        $run = craft("transport/import $tmp/probe.zip --only=00000000-0000-0000-0000-000000000000 --quiet");
        if (($r = expectExit($run, ExitCode::OK)) !== true) {
            return $r;
        }
        return freshEntry($probe->id)->title === 'Local edit' ?: 'title is now ' . freshEntry($probe->id)->title;
    });

    check('--keep-local without a field path is a usage error', fn() => expectExit(
        craft("transport/import $tmp/probe.zip --keep-local={$probe->uid}"), ExitCode::USAGE, '--keep-local expects'));
    check('a missing package exits NOINPUT', fn() => expectExit(
        craft("transport/import $tmp/missing.zip"), ExitCode::NOINPUT));

    check('a full import applies the package', function() use ($tmp, $probe) {
        $run = craft("transport/import $tmp/probe.zip --quiet");
        if (($r = expectExit($run, ExitCode::OK)) !== true) {
            return $r;
        }
        return freshEntry($probe->id)->title === 'Transport console probe' ?: 'title is now ' . freshEntry($probe->id)->title;
    });
    $updateImportId = (int)ImportHistory::find()->where(['direction' => 'import'])->max('id');

    // A package whose section doesn't exist here: pre-flight validation blocks it.
    copy("$tmp/probe.zip", "$tmp/bad.zip");
    $zip = new ZipArchive();
    $zip->open("$tmp/bad.zip");
    $json = $zip->getFromName('elements/entries.json');
    $zip->addFromString('elements/entries.json', str_replace('"reminderTestSection"', '"transportNoSuchSection"', $json));
    $zip->close();

    check('a blocked package refuses a real import (DATAERR) and changes nothing', function() use ($tmp) {
        $before = ImportHistory::find()->count();
        $r = expectExit(craft("transport/import $tmp/bad.zip"), ExitCode::DATAERR, 'Import blocked');
        return $r === true && ImportHistory::find()->count() === $before ? true : ($r === true ? 'a history row was written' : $r);
    });
    check('…the diff flags it as blocking', fn() => expectExit(
        craft("transport/import/diff $tmp/bad.zip"), ExitCode::DATAERR, 'Blocking:'));
    check('…but a dry run still goes ahead', function() use ($tmp) {
        [$code, $out] = craft("transport/import $tmp/bad.zip --dry-run --quiet");
        return str_contains($out, 'dry run') ?: "exit $code:\n$out";
    });

    echo "Rollback\n";

    check('rollback --dry-run lists what it would restore and changes nothing', function() use ($updateImportId, $probe) {
        $r = expectExit(craft("transport/rollback $updateImportId --dry-run"), ExitCode::OK, 'Restore (1)');
        if ($r !== true) {
            return $r;
        }
        $status = ImportHistory::findOne($updateImportId)->status;
        return $status === ImportHistory::STATUS_COMPLETED && freshEntry($probe->id)->title === 'Transport console probe'
            ?: "status $status, title " . freshEntry($probe->id)->title;
    });

    check('rollback --interactive=0 actually rolls back (it used to answer "no")', function() use ($updateImportId, $probe) {
        $r = expectExit(craft("transport/rollback $updateImportId --interactive=0"), ExitCode::OK, 'Rolled back: 1 restored');
        if ($r !== true) {
            return $r;
        }
        return freshEntry($probe->id)->title === 'Local edit' ?: 'title is now ' . freshEntry($probe->id)->title;
    });

    // Delete the probe, re-create it by importing, then dry-run the rollback of that.
    Craft::$app->getElements()->deleteElement(freshEntry($probe->id), true);
    [$code, $out] = craft("transport/import $tmp/probe.zip --quiet");
    $createImportId = (int)ImportHistory::find()->where(['direction' => 'import'])->max('id');
    $recreated = Entry::find()->uid($probe->uid)->status(null)->one();
    if ($recreated) {
        $probeIds[] = $recreated->id;
    }

    check('rollback --dry-run lists what the import created for deletion', fn() => $recreated
        ? expectExit(craft("transport/rollback $createImportId --dry-run"), ExitCode::OK, 'Delete (1)')
        : "re-import didn't recreate the probe:\n$out");
    check('…and the real rollback deletes it', function() use ($createImportId, $probe) {
        $r = expectExit(craft("transport/rollback $createImportId --interactive=0"), ExitCode::OK, '1 deleted');
        return $r === true && Entry::find()->uid($probe->uid)->status(null)->one() === null ?: ($r === true ? 'probe still there' : $r);
    });
    check('rolling back an export is refused', fn() => expectExit(
        craft("transport/rollback $exportHistoryId --dry-run"), ExitCode::USAGE));
    check('an unknown history ID exits NOINPUT', fn() => expectExit(
        craft('transport/rollback 999999999 --dry-run'), ExitCode::NOINPUT));

    echo "History\n";

    check('history/view prints a run\'s report', fn() => expectExit(
        craft("transport/history/view $updateImportId"), ExitCode::OK, 'Import report'));
    check('history/view on an unknown ID exits NOINPUT', fn() => expectExit(
        craft('transport/history/view 999999999'), ExitCode::NOINPUT));
    check('history/download copies an export\'s package', function() use ($exportHistoryId, $tmp, $probe) {
        $r = expectExit(craft("transport/history/download $exportHistoryId --output=$tmp/dl.zip"), ExitCode::OK, 'Wrote package');
        return $r === true ? (packageUids("$tmp/dl.zip", 'entries') === [$probe->uid] ?: 'wrong package') : $r;
    });
    check('history/download refuses an import row', fn() => expectExit(
        craft("transport/history/download $updateImportId --output=$tmp/no.zip"), ExitCode::NOINPUT));
} catch (Throwable $e) {
    $failed++;
    echo "  ✗ setup: " . $e->getMessage() . "\n";
} finally {
    foreach ($probeIds as $id) {
        if ($entry = Entry::find()->id($id)->status(null)->trashed(null)->one()) {
            Craft::$app->getElements()->deleteElement($entry, true);
        }
    }

    // Packages the runs left in Transport's temp dir, then their history and snapshots.
    $temp = Plugin::getInstance()->getSettings()->getResolvedTempPath();
    foreach (ImportHistory::find()->where(['>', 'id', $startHistoryId])->all() as $row) {
        if ($row->direction === ImportHistory::DIRECTION_EXPORT && $row->packageName) {
            @unlink($temp . DIRECTORY_SEPARATOR . basename($row->packageName));
        }
    }
    ElementSnapshot::deleteAll(['in', 'historyId', (new Query())->select('id')->from('{{%transport_history}}')->where(['>', 'id', $startHistoryId])]);
    ImportHistory::deleteAll(['>', 'id', $startHistoryId]);

    array_map('unlink', glob("$tmp/*") ?: []);
    @rmdir($tmp);
}

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
