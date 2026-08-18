<?php

namespace justinholtweb\trackr\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use justinholtweb\trackr\Plugin;
use yii\web\Response;

/**
 * CSV import in the control panel: upload, look at what it would do, then do it.
 */
class ImportController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('trackr-manageShipments');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('trackr/import/_index', [
            'aliases' => $plugin->getImports()->getAliases(),
            'statuses' => \justinholtweb\trackr\models\Shipment::statuses(),
            'isPro' => $plugin->isPro(),
            'settings' => $plugin->getSettings(),
            'preview' => null,
            'token' => null,
        ]);
    }

    /**
     * Read the uploaded file and show what would happen. Nothing is written here — an import
     * that silently half-applies is worse than one that refuses.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();

        $file = UploadedFile::getInstanceByName('file');

        if ($file === null || $file->getHasError()) {
            Craft::$app->getSession()->setError(Craft::t('trackr', 'Choose a CSV file to upload.'));

            return $this->redirect('trackr/import');
        }

        $plugin = Plugin::getInstance();
        $token = $plugin->getImports()->stashUpload($file->tempName);

        if ($token === null) {
            Craft::$app->getSession()->setError(Craft::t('trackr', 'That file couldn’t be read.'));

            return $this->redirect('trackr/import');
        }

        $path = $plugin->getImports()->stashedPath($token);
        $preview = $plugin->getImports()->preview($path);

        return $this->renderTemplate('trackr/import/_index', [
            'aliases' => $plugin->getImports()->getAliases(),
            'statuses' => \justinholtweb\trackr\models\Shipment::statuses(),
            'isPro' => $plugin->isPro(),
            'settings' => $plugin->getSettings(),
            'preview' => $preview,
            'token' => $token,
            'filename' => $file->name,
        ]);
    }

    /**
     * Import the file that was previewed.
     */
    public function actionCommit(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $token = (string)$request->getRequiredBodyParam('token');
        $notify = (bool)$request->getBodyParam('notify');

        $plugin = Plugin::getInstance();
        $path = $plugin->getImports()->stashedPath($token);

        if ($path === null) {
            Craft::$app->getSession()->setError(Craft::t('trackr', 'That upload expired. Upload the file again.'));

            return $this->redirect('trackr/import');
        }

        $result = $plugin->getImports()->import($path, $notify);
        $plugin->getImports()->discardStash($token);

        if ($result['error'] !== null) {
            Craft::$app->getSession()->setError($result['error']);

            return $this->redirect('trackr/import');
        }

        Craft::$app->getSession()->setNotice(Craft::t('trackr', '{imported} added, {updated} updated, {failed} failed.', [
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'failed' => $result['failed'],
        ]));

        return $this->renderTemplate('trackr/import/_result', [
            'result' => $result,
        ]);
    }

    /**
     * A sample CSV showing the columns Trackr understands.
     */
    public function actionSample(): Response
    {
        $csv = Plugin::getInstance()->getImports()->sampleCsv();

        return Craft::$app->getResponse()->sendContentAsFile($csv, 'trackr-sample.csv', [
            'mimeType' => 'text/csv',
        ]);
    }

    /**
     * Run the watched folder now rather than waiting for cron (Pro).
     */
    public function actionRunWatch(): Response
    {
        $this->requirePostRequest();

        $result = Plugin::getInstance()->getImports()->watch();

        if ($this->request->getAcceptsJson()) {
            return $this->asJson($result);
        }

        Craft::$app->getSession()->setNotice(Craft::t('trackr', '{files} file(s): {imported} added, {updated} updated.', [
            'files' => $result['files'],
            'imported' => $result['imported'],
            'updated' => $result['updated'],
        ]));

        return $this->redirect('trackr/import');
    }
}
