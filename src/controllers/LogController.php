<?php

namespace justinholtweb\trackr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\trackr\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The activity log (Pro).
 */
class LogController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('trackr-viewLog');

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException(Craft::t('trackr', 'The activity log is a Pro feature.'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $log = Plugin::getInstance()->getLog();

        $filters = [
            'level' => $request->getParam('level'),
            'action' => $request->getParam('action'),
            'search' => $request->getParam('search'),
        ];

        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = 100;

        return $this->renderTemplate('trackr/log/_index', [
            'entries' => $log->getEntries($filters, $perPage, ($page - 1) * $perPage),
            'total' => $log->getEntryCount($filters),
            'filters' => $filters,
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function actionDetail(int $entryId): Response
    {
        $entry = Plugin::getInstance()->getLog()->getEntryById($entryId);

        if ($entry === null) {
            throw new NotFoundHttpException('Log entry not found');
        }

        $order = $entry->orderId
            ? Plugin::getInstance()->getShipments()->getOrderById($entry->orderId)
            : null;

        return $this->renderTemplate('trackr/log/_detail', [
            'entry' => $entry,
            'order' => $order,
        ]);
    }

    public function actionPrune(): Response
    {
        $this->requirePostRequest();
        // Reading the log and erasing it are different trust: the log holds the API's rejected
        // sign-ins, and a reader who could clear it could hide a run of token guesses.
        $this->requirePermission('trackr-manageLog');

        $deleted = Plugin::getInstance()->getLog()->prune();

        return $this->asSuccess(Craft::t('trackr', '{count} entries pruned.', ['count' => $deleted]));
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('trackr-manageLog');

        $deleted = Plugin::getInstance()->getLog()->clear();

        return $this->asSuccess(Craft::t('trackr', '{count} entries deleted.', ['count' => $deleted]));
    }
}
