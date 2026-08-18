<?php

namespace justinholtweb\trackr\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\web\Controller;
use justinholtweb\trackr\Plugin;
use yii\web\Response;

/**
 * The odd jobs the settings screen needs: creating the Commerce statuses merchants expect to
 * find, minting an API token, and previewing the widget.
 */
class SettingsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin();

        return true;
    }

    /**
     * Create a Commerce order status from the settings screen.
     */
    public function actionCreateStatus(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $handle = (string)$request->getRequiredBodyParam('handle');
        $name = (string)$request->getBodyParam('name', ucfirst($handle));
        $color = (string)$request->getBodyParam('color', 'blue');

        $result = Plugin::getInstance()->getStatuses()->createStatus($handle, $name, $color);

        return $this->asJson([
            'success' => $result['created'],
            'message' => $result['message'],
            'handle' => $result['status']?->handle,
            'name' => $result['status']?->name,
        ]);
    }

    /**
     * Mint an API token. It is only returned, never stored here — the merchant saves the
     * settings screen like any other change.
     */
    public function actionGenerateToken(): Response
    {
        $this->requirePostRequest();

        return $this->asJson([
            'success' => true,
            'token' => StringHelper::UUID() . '-' . bin2hex(random_bytes(8)),
        ]);
    }

    /**
     * Render the widget with unsaved settings, for the live preview.
     */
    public function actionPreviewWidget(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $overrides = Craft::$app->getRequest()->getBodyParam('settings', []);

        if (!is_array($overrides)) {
            $overrides = [];
        }

        $html = (string)$plugin->getWidget()->renderShipments(
            null,
            $plugin->getWidget()->sampleShipments(),
            $overrides
        );

        return $this->asJson(['success' => true, 'html' => $html]);
    }
}
