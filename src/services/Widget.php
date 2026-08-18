<?php

namespace justinholtweb\trackr\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\helpers\Template;
use craft\web\View;
use justinholtweb\trackr\models\Settings;
use justinholtweb\trackr\models\Shipment;
use justinholtweb\trackr\Plugin;
use Throwable;
use Twig\Markup;

/**
 * The tracking widget: one block of markup that reads the same in an order email, on an account
 * page and on the customer tracking page.
 *
 * It is rendered with inline styles rather than a stylesheet, because the main place it is used
 * is an email, and email clients strip `<style>` blocks and never load an external one.
 */
class Widget extends Component
{
    /**
     * Render the widget for an order.
     *
     * @param array<string, mixed> $overrides settings overridden for this one render, used by
     *                                        the control panel's live preview
     */
    public function render(Order $order, array $overrides = []): Markup
    {
        $plugin = Plugin::getInstance();
        $shipments = $plugin->getShipments()->getShipmentsForOrder((int)$order->id);

        return $this->renderShipments($order, $shipments, $overrides);
    }

    /**
     * @param Shipment[] $shipments
     * @param array<string, mixed> $overrides
     */
    public function renderShipments(?Order $order, array $shipments, array $overrides = []): Markup
    {
        $plugin = Plugin::getInstance();
        $settings = $this->settingsWithOverrides($overrides);

        $variables = [
            'order' => $order,
            'shipments' => $shipments,
            'settings' => $settings,
            'styles' => $settings->getWidgetStyles(),
            'progress' => $order ? $plugin->getShipments()->getProgress($order) : null,
            'trackingPageUrl' => $order && $settings->trackingPageEnabled
                ? $settings->getTrackingPageUrl($order->uid)
                : null,
        ];

        $view = Craft::$app->getView();

        // A merchant-supplied site template wins; Trackr's own is the fallback so the widget
        // works the moment the plugin is installed.
        $custom = trim($settings->widgetTemplate);

        if ($custom !== '') {
            try {
                if ($view->doesTemplateExist($custom, View::TEMPLATE_MODE_SITE)) {
                    return Template::raw($view->renderTemplate($custom, $variables, View::TEMPLATE_MODE_SITE));
                }
            } catch (Throwable $e) {
                Craft::error('Trackr could not render the custom widget template: ' . $e->getMessage(), __METHOD__);
            }
        }

        try {
            return Template::raw($view->renderTemplate('trackr/_widget', $variables, View::TEMPLATE_MODE_CP));
        } catch (Throwable $e) {
            Craft::error('Trackr could not render the tracking widget: ' . $e->getMessage(), __METHOD__);

            return Template::raw('');
        }
    }

    /**
     * A settings model with a few values swapped, for previews. The plugin's own settings are
     * never touched — the preview must not be able to save anything.
     *
     * @param array<string, mixed> $overrides
     */
    public function settingsWithOverrides(array $overrides): Settings
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($overrides === []) {
            return $settings;
        }

        $preview = new Settings($settings->toArray());

        foreach ($overrides as $key => $value) {
            if ($preview->canSetProperty($key)) {
                // Checkboxes post strings; the model's typed properties will not take them.
                $current = $preview->$key ?? null;

                if (is_bool($current)) {
                    $value = (bool)$value && $value !== 'false';
                } elseif (is_int($current)) {
                    $value = (int)$value;
                } elseif (is_string($current)) {
                    $value = (string)$value;
                }

                $preview->$key = $value;
            }
        }

        return $preview;
    }

    /**
     * Sample shipments for the settings-screen preview, so the merchant can see the widget
     * before a single order has shipped.
     *
     * @return Shipment[]
     */
    public function sampleShipments(): array
    {
        return [
            new Shipment([
                'id' => 0,
                'providerHandle' => 'ups',
                'providerName' => 'UPS',
                'trackingNumber' => '1Z999AA10123456784',
                'service' => 'Ground',
                'status' => Shipment::STATUS_IN_TRANSIT,
                'shipDate' => new \DateTime(),
                'shippedQty' => 2,
            ]),
            new Shipment([
                'id' => 0,
                'providerHandle' => 'usps',
                'providerName' => 'USPS',
                'trackingNumber' => '9400111899223197428490',
                'service' => 'Priority Mail',
                'status' => Shipment::STATUS_DELIVERED,
                'shipDate' => (new \DateTime())->modify('-4 days'),
                'dateDelivered' => (new \DateTime())->modify('-1 day'),
                'shippedQty' => 1,
            ]),
        ];
    }
}
