<?php
/**
 * Who can read and change what through Transport — checked in the plugin-testing harness.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-transport/tests/harness/security.php
 *
 * Until 5.1.1 the Transport permissions were the whole story: `transport:export` read every
 * section, volume and user account; `transport:import` and `transport:rollback` wrote to them;
 * any control panel user could open History; and anyone with the export permission could download
 * any package — an admin's included. Each refusal is paired with what an admin (or the same user,
 * within their own permissions) is still allowed.
 *
 * The zip-slip and package-name fixes are covered by the Codeception suite
 * (AssetTransferTest, ExportConfigTest). Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\transport\models\ExportConfig;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\records\ImportHistory;

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

Craft::$app->getPlugins()->loadPlugins();

$transport = Plugin::getInstance();
if ($transport === null) {
    echo "Transport isn't installed in the harness.\n";
    exit(1);
}

$run = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'tp-' . bin2hex(random_bytes(12));
$cleanup = ['users' => [], 'entries' => [], 'history' => [], 'files' => []];

register_shutdown_function(function() use (&$cleanup) {
    foreach (array_merge($cleanup['entries'], $cleanup['users']) as $element) {
        Craft::$app->getElements()->deleteElement($element, true);
    }
    foreach ($cleanup['history'] as $id) {
        ImportHistory::deleteAll(['id' => $id]);
    }
    foreach ($cleanup['files'] as $file) {
        @unlink($file);
    }
});

// -------------------------------------------------------------------------------------------
// Fixtures: a section, an entry in it, and three users.

$sectionId = (int)(new Query())->select('sectionId')->from('{{%entries}}')->where(['not', ['sectionId' => null]])->scalar();
$section = Craft::$app->getEntries()->getSectionById($sectionId);
$siteUid = Craft::$app->getSites()->getPrimarySite()->uid;

$entry = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => "Transport original $run"]);
Craft::$app->getElements()->saveElement($entry) or throw new RuntimeException(json_encode($entry->getErrors()));
$cleanup['entries'][] = $entry;

$makeUser = static function(string $who, array $permissions) use ($run, $password, &$cleanup): User {
    $user = new User(['username' => "transport-$who-$run", 'email' => "transport-$who-$run@example.com", 'newPassword' => $password, 'firstName' => ucfirst($who)]);
    Craft::$app->getElements()->saveElement($user, false);
    Craft::$app->getUsers()->activateUser($user);
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);

    return $cleanup['users'][] = $user;
};

$base = ['accesscp', 'accessplugin-transport', "editsite:$siteUid"];
$nobody = $makeUser('nobody', $base);
// Every Transport permission, but nothing in the section and no user management.
$migrator = $makeUser('migrator', array_merge($base, ['transport:export', 'transport:import', 'transport:rollback']));
// Every Transport permission plus full rights over the section and over users.
$editor = $makeUser('editor', array_merge($base, ['transport:export', 'transport:import', 'transport:rollback', 'viewusers', 'editusers',
    "viewentries:{$section->uid}", "saveentries:{$section->uid}", "createentries:{$section->uid}", "deleteentries:{$section->uid}",
    "viewpeerentries:{$section->uid}", "savepeerentries:{$section->uid}", "deletepeerentries:{$section->uid}"]));
$admin = User::find()->admin(true)->one();

function client(string $username, string $password): Client
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $json = ['Accept' => 'application/json'];
    $csrf = (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
    $login = $http->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf]]);

    if ($login->getStatusCode() !== 200) {
        echo "Could not sign in as $username\n";
        exit(1);
    }

    return $http;
}

/** Runs an export as `$userId` (null = console) and returns [titles in the package, history id]. */
$export = static function(?int $userId, string $name) use ($transport, $section, &$cleanup): array {
    $config = new ExportConfig(['packageKeys' => ['entries'], 'section' => $section->handle, 'packageName' => $name, 'userId' => $userId]);
    $report = $transport->export->run($config);
    $cleanup['history'][] = $report->historyId;
    $cleanup['files'][] = $report->packagePath;
    $titles = [];
    if ($report->packagePath && is_file($report->packagePath)) {
        foreach ($transport->packages->open($report->packagePath)->allElements() as $data) {
            foreach ($data['sites'] ?? [] as $site) {
                $titles[] = $site['title'] ?? null;
            }
        }
    }

    return [$titles, $report->historyId, $report->packagePath];
};

// -------------------------------------------------------------------------------------------
echo "\nHistory\n";

$nobodyHttp = client($nobody->username, $password);
$migratorHttp = client($migrator->username, $password);

check('someone with no Transport permission can’t open it', fn() => ($s = $nobodyHttp->get('index.php?p=admin/transport/history')->getStatusCode()) === 403 ?: "status $s");
check('…or a history row', function() use ($nobodyHttp, $export, $run) {
    [, $historyId] = $export(null, "tp-sec-row-$run");

    return ($s = $nobodyHttp->get("index.php?p=admin/transport/history/$historyId")->getStatusCode()) === 403 ?: "status $s";
});
check('someone with a Transport permission can', fn() => ($s = $migratorHttp->get('index.php?p=admin/transport/history')->getStatusCode()) === 200 ?: "status $s");

// -------------------------------------------------------------------------------------------
echo "\nExport\n";

check('an export holds only what its exporter can view', function() use ($export, $migrator, $run) {
    [$titles] = $export($migrator->id, "tp-sec-limited-$run");

    return !in_array("Transport original $run", $titles, true) ?: 'section exported';
});
check('…and an editor of the section gets it', function() use ($export, $editor, $run) {
    [$titles] = $export($editor->id, "tp-sec-editor-$run");

    return in_array("Transport original $run", $titles, true) ?: json_encode($titles);
});
check('…as does a console run', function() use ($export, $run) {
    [$titles] = $export(null, "tp-sec-console-$run");

    return in_array("Transport original $run", $titles, true) ?: json_encode($titles);
});

check('an admin’s package can’t be downloaded by someone else with the export permission', function() use ($export, $admin, $migratorHttp, $run) {
    [, $historyId] = $export($admin->id, "tp-sec-admin-$run");

    return ($s = $migratorHttp->get("index.php?p=admin/actions/transport/history/download&id=$historyId")->getStatusCode()) === 403 ?: "status $s";
});
check('…but your own can', function() use ($export, $migrator, $migratorHttp, $run) {
    [, $historyId] = $export($migrator->id, "tp-sec-own-$run");
    $response = $migratorHttp->get("index.php?p=admin/actions/transport/history/download&id=$historyId");

    return $response->getStatusCode() === 200 && str_starts_with((string)$response->getBody(), 'PK') ?: 'status ' . $response->getStatusCode();
});

// -------------------------------------------------------------------------------------------
echo "\nImport\n";

/** An entries package of the section as it stands, with the test entry's title changed (found by UID). */
$editedPackage = static function(string $title) use ($export, $run, $entry): string {
    [, , $path] = $export(null, "tp-sec-edit-$run-" . bin2hex(random_bytes(2)));
    $zip = new ZipArchive();
    $zip->open($path);
    $entries = json_decode((string)$zip->getFromName('elements/entries.json'), true);
    foreach ($entries as &$data) {
        if (($data['uid'] ?? null) !== $entry->uid) {
            continue;
        }
        foreach ($data['sites'] as &$site) {
            $site['title'] = $title;
        }
    }
    unset($data, $site);
    $zip->addFromString('elements/entries.json', json_encode($entries));
    $zip->close();

    return $path;
};

$titleNow = static fn() => Entry::find()->id($entry->id)->status(null)->one()?->title;

check('an import can’t write to a section its importer can’t edit', function() use ($transport, $editedPackage, $migrator, $titleNow, $run, &$cleanup) {
    $report = $transport->import->run($editedPackage("Changed by migrator $run"), false, [], ['userId' => $migrator->id, 'selectedUids' => null]);
    $cleanup['history'][] = $report->historyId;

    return $titleNow() === "Transport original $run" && $report->errors ?: json_encode([$titleNow(), $report->errors]);
});
check('…but an editor of the section can', function() use ($transport, $editedPackage, $editor, $titleNow, $run, &$cleanup) {
    $report = $transport->import->run($editedPackage("Changed by editor $run"), false, [], ['userId' => $editor->id]);
    $cleanup['history'][] = $report->historyId;

    return $titleNow() === "Changed by editor $run" ?: json_encode([$titleNow(), $report->errors]);
});

/** A users package with one user's first name changed. */
$usersPackage = static function(User $target, string $firstName) use ($transport, $run, &$cleanup): string {
    $config = new ExportConfig(['packageKeys' => ['users'], 'packageName' => "tp-sec-users-$run-" . bin2hex(random_bytes(2))]);
    $report = $transport->export->run($config);
    $cleanup['history'][] = $report->historyId;
    $cleanup['files'][] = $report->packagePath;
    $zip = new ZipArchive();
    $zip->open($report->packagePath);
    $users = array_values(array_filter(json_decode((string)$zip->getFromName('elements/users.json'), true), fn($u) => ($u['uid'] ?? null) === $target->uid));
    $users[0]['attributes']['firstName'] = $firstName;
    $zip->addFromString('elements/users.json', json_encode($users));
    $zip->close();

    return $report->packagePath;
};

check('someone who can edit users still can’t change an admin’s account by import', function() use ($transport, $usersPackage, $admin, $editor, $run, &$cleanup) {
    $before = $admin->firstName;
    $report = $transport->import->run($usersPackage($admin, "Renamed $run"), false, [], ['userId' => $editor->id]);
    $cleanup['history'][] = $report->historyId;
    $after = User::find()->id($admin->id)->one()->firstName;

    return $after === $before && $report->errors ?: json_encode([$after, $report->errors]);
});
check('…but can change a non-admin’s', function() use ($transport, $usersPackage, $nobody, $editor, $run, &$cleanup) {
    $report = $transport->import->run($usersPackage($nobody, "Renamed $run"), false, [], ['userId' => $editor->id]);
    $cleanup['history'][] = $report->historyId;

    return User::find()->id($nobody->id)->status(null)->one()->firstName === "Renamed $run" ?: json_encode($report->errors);
});

// -------------------------------------------------------------------------------------------
echo "\nRollback\n";

check('a rollback can’t undo changes in a section you can’t edit', function() use ($transport, $editedPackage, $titleNow, $migratorHttp, $run, &$cleanup) {
    $report = $transport->import->run($editedPackage("Before rollback $run"), false);
    $cleanup['history'][] = $report->historyId;

    $csrf = (string)(json_decode((string)$migratorHttp->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');
    $migratorHttp->post('index.php?p=admin/actions/transport/history/rollback', ['form_params' => ['id' => $report->historyId, 'CRAFT_CSRF_TOKEN' => $csrf]]);
    foreach ((new Query())->select('id')->from('{{%transport_history}}')->where(['packageName' => "Rollback of #{$report->historyId}"])->column() as $id) {
        $cleanup['history'][] = (int)$id;
    }

    return $titleNow() === "Before rollback $run"
        && ImportHistory::findOne($report->historyId)->status !== ImportHistory::STATUS_ROLLED_BACK
        ?: json_encode([$titleNow(), ImportHistory::findOne($report->historyId)->status]);
});

check('…and an admin can', function() use ($transport, $editedPackage, $titleNow, $run, &$cleanup) {
    $report = $transport->import->run($editedPackage("Before admin rollback $run"), false);
    $cleanup['history'][] = $report->historyId;
    $result = $transport->snapshots->rollback(ImportHistory::findOne($report->historyId));
    foreach ((new Query())->select('id')->from('{{%transport_history}}')->where(['packageName' => "Rollback of #{$report->historyId}"])->column() as $id) {
        $cleanup['history'][] = (int)$id;
    }

    return !$result['errors'] && $titleNow() !== "Before admin rollback $run" ?: json_encode([$titleNow(), $result['errors']]);
});

// -------------------------------------------------------------------------------------------
echo "\nUploads\n";

$upload = static function(Client $http, string $contents) {
    $csrf = (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');
    $http->post('index.php?p=admin/actions/transport/import/configure', ['multipart' => [
        ['name' => 'CRAFT_CSRF_TOKEN', 'contents' => $csrf],
        ['name' => 'package', 'contents' => $contents, 'filename' => 'package.zip'],
    ]]);

    // The refusal is a session flash, shown on the next page.
    return (string)$http->get('index.php?p=admin/transport/import')->getBody();
};

check('a file that isn’t a zip is refused at upload', fn() => str_contains($upload($migratorHttp, 'not a zip at all'), 'isn’t a Transport package') ?: 'accepted');

check('the package size limit is enforced', function() use ($upload, $migratorHttp) {
    $projectConfig = Craft::$app->getProjectConfig();
    // Other work in the harness may have changed project config since this script booted.
    Craft::$app->getInfo()->configVersion = (string)(new Query())->select('configVersion')->from('{{%info}}')->scalar();
    $projectConfig->reset();
    $before = $projectConfig->get('plugins.transport.settings.maxPackageSize');

    try {
        $projectConfig->set('plugins.transport.settings.maxPackageSize', 1);
        $projectConfig->saveModifiedConfigData();
        $projectConfig->writeYamlFiles(true);

        return str_contains($upload($migratorHttp, random_bytes(1100 * 1024)), 'larger than the 1 MB limit') ?: 'accepted';
    } finally {
        // Other sessions write the harness's YAML too; a collision throws, so retry rather than
        // leave the limit at 1 MB.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                Craft::$app->getInfo()->configVersion = (string)(new Query())->select('configVersion')->from('{{%info}}')->scalar();
                $projectConfig->reset();
                $before === null ? $projectConfig->remove('plugins.transport.settings.maxPackageSize') : $projectConfig->set('plugins.transport.settings.maxPackageSize', $before);
                $projectConfig->saveModifiedConfigData();
                $projectConfig->writeYamlFiles(true);
                break;
            } catch (Throwable $e) {
                if ($attempt === 5) {
                    echo "    !! Couldn't restore maxPackageSize: {$e->getMessage()}\n";
                }
                sleep(2);
            }
        }
    }
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
