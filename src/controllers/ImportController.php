<?php

namespace justinholtweb\transport\controllers;

use Craft;
use craft\helpers\FileHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use justinholtweb\transport\models\DiffResult;
use justinholtweb\transport\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response as YiiResponse;

/**
 * The import wizard: upload → configure → preview → run.
 */
class ImportController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission(Plugin::PERMISSION_IMPORT);
        return parent::beforeAction($action);
    }

    /**
     * Step 1 — upload form.
     */
    public function actionUpload(): YiiResponse
    {
        return $this->renderTemplate('transport/import/upload', []);
    }

    /**
     * Step 2 — accept the uploaded package, diff it, and show per-element actions.
     */
    public function actionConfigure(): YiiResponse
    {
        $this->requirePostRequest();

        $token = $this->ingestUpload();
        if ($token === null) {
            return $this->redirect('transport/import');
        }

        $package = Plugin::getInstance()->packages->open($this->pathFromToken($token));
        $diffs = Plugin::getInstance()->differ->diffPackage($package);
        $validation = Plugin::getInstance()->validation->validate($package);

        return $this->renderTemplate('transport/import/configure', [
            'token' => $token,
            'groups' => $this->groupByKey($diffs),
            'summary' => $this->summary($diffs),
            'validation' => $validation,
        ]);
    }

    /**
     * Step 3 — field-level diff preview for the selected elements.
     */
    public function actionPreview(): YiiResponse
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $token = $this->tokenFromRequest();
        $selectedUids = array_values((array)$request->getBodyParam('uids', []));

        $package = Plugin::getInstance()->packages->open($this->pathFromToken($token));
        $diffs = array_filter(
            Plugin::getInstance()->differ->diffPackage($package),
            static fn(DiffResult $d) => in_array($d->uid, $selectedUids, true)
        );

        return $this->renderTemplate('transport/import/preview', [
            'token' => $token,
            'selectedUids' => $selectedUids,
            'diffs' => array_values($diffs),
        ]);
    }

    /**
     * Step 4 — apply selections + merge decisions and run the import.
     */
    public function actionRun(): YiiResponse
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $token = $this->tokenFromRequest();
        $path = $this->pathFromToken($token);

        // Block real imports when pre-flight validation finds blocking errors.
        $package = Plugin::getInstance()->packages->open($path);
        $validation = Plugin::getInstance()->validation->validate($package);
        if ($validation['errors'] && !$request->getBodyParam('dryRun')) {
            Craft::$app->getSession()->setError(Craft::t('transport', 'Import blocked: {err}', [
                'err' => implode('; ', $validation['errors']),
            ]));
            return $this->redirect('transport/import');
        }

        $selectedUids = array_values((array)$request->getBodyParam('uids', []));
        $dryRun = (bool)$request->getBodyParam('dryRun', false);

        // Reconstruct rejected paths: every change path the user did not keep "applied".
        $allPaths = (array)$request->getBodyParam('paths', []);
        $applied = (array)$request->getBodyParam('apply', []);
        $decisions = [];
        foreach ($allPaths as $uid => $joined) {
            $paths = array_filter(explode(',', (string)$joined));
            $keep = (array)($applied[$uid] ?? []);
            $rejected = array_values(array_diff($paths, $keep));
            if ($rejected) {
                $decisions[$uid] = $rejected;
            }
        }

        $importOptions = [
            'selectedUids' => $selectedUids ?: null,
            'decisions' => $decisions,
        ];

        // Run large imports in the background when requested.
        if ($request->getBodyParam('queue') && !$dryRun) {
            Craft::$app->getQueue()->push(new \justinholtweb\transport\queue\ImportJob([
                'path' => $path,
                'dryRun' => false,
                'options' => $importOptions,
            ]));
            Craft::$app->getSession()->setNotice(Craft::t('transport', 'Import queued.'));
            return $this->redirect('transport/history');
        }

        $result = Plugin::getInstance()->import->importPackage($path, $dryRun, [], $importOptions);

        return $this->renderTemplate('transport/import/run', [
            'result' => $result,
            'dryRun' => $dryRun,
        ]);
    }

    /**
     * Saves the uploaded package to the temp dir and returns its token (filename).
     */
    private function ingestUpload(): ?string
    {
        $file = UploadedFile::getInstanceByName('package');
        if (!$file) {
            Craft::$app->getSession()->setError(Craft::t('transport', 'No package was uploaded.'));
            return null;
        }

        $tempPath = Plugin::getInstance()->getSettings()->getResolvedTempPath();
        FileHelper::createDirectory($tempPath);

        $token = 'import-' . bin2hex(random_bytes(8)) . '.zip';
        if (!$file->saveAs($tempPath . DIRECTORY_SEPARATOR . $token)) {
            Craft::$app->getSession()->setError(Craft::t('transport', 'Couldn’t save the uploaded package.'));
            return null;
        }

        return $token;
    }

    private function tokenFromRequest(): string
    {
        $token = Craft::$app->getRequest()->getRequiredBodyParam('token');
        return $this->sanitizeToken($token);
    }

    /**
     * Resolves a token to an absolute path inside the temp directory, rejecting any
     * attempt to escape it.
     */
    private function pathFromToken(string $token): string
    {
        $token = $this->sanitizeToken($token);
        $tempPath = Plugin::getInstance()->getSettings()->getResolvedTempPath();
        $path = $tempPath . DIRECTORY_SEPARATOR . $token;

        if (!is_file($path)) {
            throw new BadRequestHttpException('Package not found.');
        }

        return $path;
    }

    private function sanitizeToken(string $token): string
    {
        $token = basename($token);
        if (!preg_match('/^import-[a-f0-9]+\.zip$/', $token)) {
            throw new BadRequestHttpException('Invalid package token.');
        }
        return $token;
    }

    /**
     * @param DiffResult[] $diffs
     * @return array<string, DiffResult[]>
     */
    private function groupByKey(array $diffs): array
    {
        $groups = [];
        foreach ($diffs as $diff) {
            $groups[$diff->key][] = $diff;
        }
        ksort($groups);
        return $groups;
    }

    /**
     * @param DiffResult[] $diffs
     * @return array{add:int,update:int,unchanged:int}
     */
    private function summary(array $diffs): array
    {
        $summary = ['add' => 0, 'update' => 0, 'unchanged' => 0];
        foreach ($diffs as $diff) {
            $summary[$diff->action]++;
        }
        return $summary;
    }
}
