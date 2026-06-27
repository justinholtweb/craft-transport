<?php

namespace justinholtweb\transport\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\transport\Plugin;
use yii\web\Response as YiiResponse;

/**
 * Plugin settings, rendered as a Transport CP section so it can live in the subnav.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireAdmin();
        return parent::beforeAction($action);
    }

    public function actionIndex(): YiiResponse
    {
        return $this->renderTemplate('transport/settings/index', [
            'settings' => Plugin::getInstance()->getSettings(),
            'plugin' => Plugin::getInstance(),
        ]);
    }

    public function actionSave(): ?YiiResponse
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $params = Craft::$app->getRequest()->getBodyParam('settings', []);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $params)) {
            Craft::$app->getSession()->setError(Craft::t('transport', 'Couldn’t save settings.'));
            return $this->renderTemplate('transport/settings/index', [
                'settings' => $plugin->getSettings(),
                'plugin' => $plugin,
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('transport', 'Settings saved.'));
        return $this->redirectToPostedUrl();
    }
}
