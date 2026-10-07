<?php

namespace justinholtweb\trackr\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\trackr\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The customer-facing tracking page.
 *
 * Guests get their fulfilment without an account: either from an emailed link carrying the
 * order's UID, or by entering the order number together with the email address on the order.
 */
class TrackController extends Controller
{
    /**
     * @inheritdoc
     */
    public array|bool|int $allowAnonymous = true;

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->trackingPageEnabled) {
            throw new NotFoundHttpException();
        }

        $request = Craft::$app->getRequest();
        $tracking = $plugin->getTracking();

        $order = null;
        $error = null;
        $submitted = false;

        $token = (string)$request->getParam('t', '');

        if ($token !== '') {
            $submitted = true;
            $order = $tracking->lookupByToken($token);

            if ($order === null) {
                $error = Craft::t('trackr', 'That tracking link is no longer valid.');
            }
        } elseif ($request->getIsPost() || $request->getParam('orderNumber') !== null) {
            $submitted = true;

            $result = $tracking->lookup(
                $request->getParam('orderNumber'),
                $request->getParam('email'),
                // The connecting address, not getUserIP(): that believes X-Forwarded-For from anyone,
                // so a new header was a fresh allowance of guesses.
                \justinholtweb\trackr\helpers\RateLimit::client()
            );

            $order = $result['order'];
            $error = $result['error'];
        }

        $variables = [
            'settings' => $settings,
            'styles' => $settings->getWidgetStyles(),
            'order' => $order,
            'error' => $error,
            'submitted' => $submitted,
            'orderNumber' => (string)$request->getParam('orderNumber', ''),
            'email' => (string)$request->getParam('email', ''),
            'shipments' => [],
            'progress' => null,
        ];

        if ($order !== null) {
            $summary = $tracking->summarize($order);
            $variables['shipments'] = $summary['shipments'];
            $variables['progress'] = $summary['progress'];
            $variables['state'] = $summary['state'];
        }

        $view = Craft::$app->getView();
        $custom = trim($settings->trackingPageTemplate);

        if ($custom !== '' && $view->doesTemplateExist($custom, View::TEMPLATE_MODE_SITE)) {
            return $this->renderTemplate($custom, $variables);
        }

        // Trackr's own page, so the feature works before anyone has built a template for it.
        return $this->asRaw($view->renderTemplate('trackr/track/_index', $variables, View::TEMPLATE_MODE_CP));
    }
}
