<?php

namespace justinholtweb\trackr\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\helpers\App;
use craft\web\View;
use justinholtweb\trackr\models\Shipment;
use justinholtweb\trackr\Plugin;
use justinholtweb\trackr\records\ShipmentRecord;
use justinholtweb\trackr\twig\TrackrVariable;
use Throwable;

/**
 * Customer emails Trackr sends itself.
 *
 * Commerce already emails on a status change, and for most stores that is the right place for
 * tracking to appear — drop `craft.trackr.widget(order)` into the Commerce email template and
 * nothing here is needed. These exist for the stores where a shipment does not move the order's
 * status at all, and for the delivery notification Commerce has no concept of.
 *
 * Sending never throws: a mail server being down must not lose the tracking number that has
 * already been recorded.
 */
class Notifications extends Component
{
    public function sendShipmentEmail(Order $order, Shipment $shipment): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!Plugin::getInstance()->isPro()) {
            return false;
        }

        $sent = $this->send(
            $order,
            $shipment,
            $settings->shipmentEmailSubject,
            $settings->shipmentEmailTemplate,
            'trackr/_email-shipment'
        );

        if ($sent && $shipment->id) {
            // Recorded so a second import of the same CSV does not email the customer twice.
            $record = ShipmentRecord::findOne(['id' => $shipment->id]);

            if ($record !== null) {
                $record->customerNotified = true;
                $record->save(false);
            }
        }

        return $sent;
    }

    public function sendDeliveryEmail(Order $order, Shipment $shipment): bool
    {
        if (!Plugin::getInstance()->isPro()) {
            return false;
        }

        $settings = Plugin::getInstance()->getSettings();

        return $this->send(
            $order,
            $shipment,
            $settings->deliveryEmailSubject,
            $settings->deliveryEmailTemplate,
            'trackr/_email-delivered'
        );
    }

    /**
     * Fill `{orderNumber}`, `{trackingNumber}`, `{provider}` and `{siteName}` in a subject line.
     */
    public function renderSubject(string $subject, Order $order, Shipment $shipment): string
    {
        return strtr($subject, [
            '{orderNumber}' => TrackrVariable::numberFor($order),
            '{orderReference}' => (string)$order->reference,
            '{trackingNumber}' => (string)$shipment->trackingNumber,
            '{provider}' => $shipment->getProviderLabel(),
            '{siteName}' => (string)App::parseEnv(Craft::$app->getSites()->getCurrentSite()->getName()),
        ]);
    }

    private function send(
        Order $order,
        Shipment $shipment,
        string $subjectTemplate,
        string $customTemplate,
        string $fallbackTemplate,
    ): bool {
        $email = trim((string)$order->getEmail());

        if ($email === '') {
            return false;
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $view = Craft::$app->getView();

        $variables = [
            'order' => $order,
            'shipment' => $shipment,
            'shipments' => $plugin->getShipments()->getShipmentsForOrder((int)$order->id),
            'settings' => $settings,
            'styles' => $settings->getWidgetStyles(),
            'trackingPageUrl' => $settings->trackingPageEnabled ? $settings->getTrackingPageUrl($order->uid) : null,
        ];

        try {
            $custom = trim($customTemplate);

            if ($custom !== '' && $view->doesTemplateExist($custom, View::TEMPLATE_MODE_SITE)) {
                $body = $view->renderTemplate($custom, $variables, View::TEMPLATE_MODE_SITE);
            } else {
                $body = $view->renderTemplate($fallbackTemplate, $variables, View::TEMPLATE_MODE_CP);
            }

            $message = Craft::$app->getMailer()->compose()
                ->setTo($email)
                ->setSubject($this->renderSubject($subjectTemplate, $order, $shipment))
                ->setHtmlBody($body)
                ->setTextBody(trim(strip_tags(preg_replace('/<(br|\/p|\/tr|\/div)[^>]*>/i', "\n", $body) ?? $body)));

            $sent = $message->send();
        } catch (Throwable $e) {
            Craft::error('Trackr could not send a tracking email: ' . $e->getMessage(), __METHOD__);

            $plugin->getLog()->write('email.failed', Craft::t('trackr', 'Email to {email} failed', ['email' => $email]), [
                'orderId' => (int)$order->id,
                'level' => 'error',
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        $plugin->getLog()->write(
            $sent ? 'email.sent' : 'email.failed',
            Craft::t('trackr', 'Tracking email to {email}', ['email' => $email]),
            ['orderId' => (int)$order->id, 'level' => $sent ? 'info' : 'warning']
        );

        return $sent;
    }
}
