<?php

namespace justinholtweb\transport\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\transport\models\ExportConfig;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\queue\ExportJob;
use yii\web\Response as YiiResponse;

/**
 * Export screen + actions.
 */
class ExportController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission(Plugin::PERMISSION_EXPORT);
        return parent::beforeAction($action);
    }

    public function actionIndex(): YiiResponse
    {
        return $this->renderTemplate('transport/export/index', [
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'packageKeys' => $this->packageKeys(),
            'settings' => Plugin::getInstance()->getSettings(),
        ]);
    }

    /**
     * @return string[] Available element-type package keys.
     */
    private function packageKeys(): array
    {
        $keys = [];
        foreach (Plugin::getInstance()->elementRegistry->all() as $handler) {
            $keys[] = $handler->packageKey();
        }
        sort($keys);
        return $keys;
    }

    /**
     * Queues an export. Control panel exports always run in the background so a large
     * site can't blow the request timeout — the finished package is downloaded from the
     * History screen, and the user can ask to be emailed when it lands.
     */
    public function actionRun(): YiiResponse
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $config = new ExportConfig();
        $config->section = $request->getBodyParam('section') ?: null;
        $config->site = $request->getBodyParam('site') ?: null;
        $config->packageKeys = array_values(array_filter((array)$request->getBodyParam('packageKeys', ['entries'])));
        $config->elementIds = array_filter(array_map('intval', (array)$request->getBodyParam('elementIds', [])));
        $config->includeAssetFiles = (bool)$request->getBodyParam('includeAssetFiles', true);
        $config->packageName = $request->getBodyParam('packageName') ?: null;

        if (!$config->packageKeys) {
            $config->packageKeys = ['entries'];
        }

        // Credit (and notify) the user who started it — the queue worker has no session
        // of its own.
        $config->userId = Craft::$app->getUser()->getId();

        if (!$config->validate()) {
            Craft::$app->getSession()->setError(Craft::t('transport', 'Couldn’t start export.'));
            return $this->renderTemplate('transport/export/index', [
                'sections' => Craft::$app->getEntries()->getAllSections(),
                'packageKeys' => $this->packageKeys(),
                'settings' => Plugin::getInstance()->getSettings(),
                'config' => $config,
            ]);
        }

        Craft::$app->getQueue()->push(new ExportJob([
            'config' => $config->toArray(),
            'notify' => (bool)$request->getBodyParam('notify'),
        ]));

        Craft::$app->getSession()->setNotice(Craft::t(
            'transport',
            'Export queued. The package will be available to download from History when it finishes.'
        ));

        return $this->redirect('transport/history');
    }
}
