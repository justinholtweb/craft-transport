<?php

namespace justinholtweb\transport\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\records\ImportHistory;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response as YiiResponse;

/**
 * Import/export history and rollback.
 */
class HistoryController extends Controller
{
    /**
     * History holds reports, error logs and element titles from every section, so it needs one
     * of the Transport permissions. Before 5.1.1 any control panel user could open it.
     */
    public function beforeAction($action): bool
    {
        if (!Plugin::canUseTransport()) {
            throw new ForbiddenHttpException('User is not permitted to perform this action.');
        }

        return parent::beforeAction($action);
    }

    public function actionIndex(): YiiResponse
    {
        $history = ImportHistory::find()
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(100)
            ->all();

        return $this->renderTemplate('transport/history/index', [
            'history' => $history,
            'canRollback' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_ROLLBACK),
            'canDownload' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_EXPORT),
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
            'report' => $record->getRunReport(),
            'errors' => is_array($errors) ? $errors : [$record->errorLog],
            'canRollback' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_ROLLBACK),
            'canDownload' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_EXPORT),
            'packageAvailable' => $this->packagePath($record) !== null,
        ]);
    }

    /**
     * Streams the package a queued export produced. Control panel exports run in the
     * background, so this is how the finished package gets to the user.
     */
    public function actionDownload(int $id): YiiResponse
    {
        $this->requirePermission(Plugin::PERMISSION_EXPORT);

        $record = ImportHistory::findOne(['id' => $id]);
        if (!$record || $record->direction !== ImportHistory::DIRECTION_EXPORT) {
            throw new NotFoundHttpException();
        }

        // A package holds what its exporter could view, which may be more than you can.
        if (!self::canDownload($record)) {
            throw new ForbiddenHttpException('Only an admin or the person who exported it can download this package.');
        }

        $path = $this->packagePath($record);
        if ($path === null) {
            Craft::$app->getSession()->setError(Craft::t(
                'transport',
                'That package is no longer on disk.'
            ));
            return $this->redirect('transport/history');
        }

        return Craft::$app->getResponse()->sendFile($path, basename($path), [
            'mimeType' => 'application/zip',
        ]);
    }

    /**
     * The package this history row refers to, if it is still staged in the temp
     * directory. The filename is taken apart with basename() so a crafted record can't
     * point outside it.
     */
    private function packagePath(ImportHistory $record): ?string
    {
        if ($record->direction !== ImportHistory::DIRECTION_EXPORT || !$record->packageName) {
            return null;
        }

        $path = Plugin::getInstance()->getSettings()->getResolvedTempPath()
            . DIRECTORY_SEPARATOR
            . basename($record->packageName);

        return is_file($path) ? $path : null;
    }

    /**
     * Whether the current user may download an export's package: an admin, or whoever ran it.
     */
    public static function canDownload(ImportHistory $record): bool
    {
        $user = Craft::$app->getUser();

        return $user->checkPermission(Plugin::PERMISSION_EXPORT)
            && ($user->getIsAdmin() || ($record->userId !== null && (int)$record->userId === (int)$user->getId()));
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
