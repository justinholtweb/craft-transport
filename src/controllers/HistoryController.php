<?php

namespace justinholtweb\transport\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\records\ImportHistory;
use yii\web\NotFoundHttpException;
use yii\web\Response as YiiResponse;

/**
 * Import/export history and rollback.
 */
class HistoryController extends Controller
{
    public function actionIndex(): YiiResponse
    {
        $history = ImportHistory::find()
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(100)
            ->all();

        return $this->renderTemplate('transport/history/index', [
            'history' => $history,
            'canRollback' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_ROLLBACK),
        ]);
    }

    public function actionDetail(int $id): YiiResponse
    {
        $record = ImportHistory::findOne(['id' => $id]);
        if (!$record) {
            throw new NotFoundHttpException();
        }

        $errors = $record->errorLog ? (Json::decodeIfJson($record->errorLog) ?: []) : [];

        return $this->renderTemplate('transport/history/detail', [
            'record' => $record,
            'errors' => is_array($errors) ? $errors : [$record->errorLog],
            'canRollback' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_ROLLBACK),
        ]);
    }

    public function actionRollback(): YiiResponse
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_ROLLBACK);

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $record = ImportHistory::findOne(['id' => $id]);

        if (!$record) {
            throw new NotFoundHttpException();
        }

        if ($record->direction !== ImportHistory::DIRECTION_IMPORT || !$record->snapshotId) {
            Craft::$app->getSession()->setError(Craft::t('transport', 'This operation can’t be rolled back.'));
            return $this->redirect('transport/history');
        }

        $result = Plugin::getInstance()->snapshots->rollback($record);

        if ($result['errors']) {
            Craft::$app->getSession()->setError(Craft::t('transport', 'Rollback failed: {err}', [
                'err' => implode('; ', $result['errors']),
            ]));
        } else {
            Craft::$app->getSession()->setNotice(Craft::t('transport', 'Rolled back: {r} restored, {d} deleted.', [
                'r' => $result['restored'],
                'd' => $result['deleted'],
            ]));
        }

        return $this->redirect('transport/history');
    }
}
