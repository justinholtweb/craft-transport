<?php

namespace justinholtweb\transport\controllers;

use Craft;
use craft\web\Controller;
use craft\web\Response;
use justinholtweb\transport\models\ExportConfig;
use justinholtweb\transport\Plugin;
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
     * Runs an export and streams the resulting package as a download.
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

        if (!$config->validate()) {
            Craft::$app->getSession()->setError(Craft::t('transport', 'Couldn’t start export.'));
            return $this->renderTemplate('transport/export/index', [
                'sections' => Craft::$app->getEntries()->getAllSections(),
                'config' => $config,
            ]);
        }

        $path = Plugin::getInstance()->export->export($config);

        /** @var Response $response */
        $response = Craft::$app->getResponse();
        return $response->sendFile($path, basename($path), ['mimeType' => 'application/zip']);
    }
}
