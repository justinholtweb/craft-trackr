<?php

namespace justinholtweb\trackr\twig;

use craft\commerce\elements\Order;
use justinholtweb\trackr\models\OrderState;
use justinholtweb\trackr\models\Provider;
use justinholtweb\trackr\models\Shipment;
use justinholtweb\trackr\Plugin;
use Twig\Markup;

/**
 * `craft.trackr.*` — everything a front-end template, an account page or a Commerce email
 * template needs.
 *
 * Every method that takes an order accepts the element or its id, because email templates and
 * account pages tend to have one or the other and not always the same one.
 */
class TrackrVariable
{
    /**
     * Shipments recorded against an order, oldest first.
     *
     * @return Shipment[]
     */
    public function shipments(Order|int|null $order): array
    {
        $orderId = $this->orderId($order);

        return $orderId !== null ? Plugin::getInstance()->getShipments()->getShipmentsForOrder($orderId) : [];
    }

    /**
     * The most recent shipment, or null.
     */
    public function latest(Order|int|null $order): ?Shipment
    {
        $shipments = $this->shipments($order);

        return $shipments !== [] ? end($shipments) : null;
    }

    public function hasShipped(Order|int|null $order): bool
    {
        return $this->state($order)->getHasShipped();
    }

    public function isFullyShipped(Order|int|null $order): bool
    {
        return $this->state($order)->fullyShipped;
    }

    public function isDelivered(Order|int|null $order): bool
    {
        return $this->state($order)->getIsDelivered();
    }

    public function state(Order|int|null $order): OrderState
    {
        $orderId = $this->orderId($order);

        return Plugin::getInstance()->getShipments()->getOrderState($orderId ?? 0);
    }

    /**
     * Fulfilment progress: `{shipped, total, remaining, percent, fullyShipped, shipmentCount}`.
     *
     * @return array<string, mixed>
     */
    public function progress(Order|int|null $order): array
    {
        $order = $this->resolveOrder($order);

        if ($order === null) {
            return ['shipped' => 0, 'total' => 0, 'remaining' => 0, 'percent' => 0, 'fullyShipped' => false, 'shipmentCount' => 0];
        }

        return Plugin::getInstance()->getShipments()->getProgress($order);
    }

    /**
     * The tracking widget, ready to drop into an order email or an account page.
     *
     * @param array<string, mixed> $overrides
     */
    public function widget(Order|int|null $order, array $overrides = []): Markup
    {
        $order = $this->resolveOrder($order);

        if ($order === null) {
            return Plugin::getInstance()->getWidget()->renderShipments(null, [], $overrides);
        }

        return Plugin::getInstance()->getWidget()->render($order, $overrides);
    }

    /**
     * A tracking URL from a carrier and a number, for stores that keep tracking somewhere else.
     *
     * @param array<string, string> $context
     */
    public function trackingUrl(?string $provider, ?string $trackingNumber, array $context = []): ?string
    {
        return Plugin::getInstance()->getProviders()->trackingUrl($provider, $trackingNumber, $context);
    }

    /**
     * @return array<string, Provider>
     */
    public function providers(bool $enabledOnly = true): array
    {
        $providers = Plugin::getInstance()->getProviders();

        return $enabledOnly ? $providers->getEnabledProviders() : $providers->getAllProviders();
    }

    public function provider(?string $handle): ?Provider
    {
        return Plugin::getInstance()->getProviders()->getProviderByHandle($handle);
    }

    /**
     * Work out the carrier from the shape of a tracking number (Pro).
     */
    public function detect(?string $trackingNumber): ?Provider
    {
        return Plugin::getInstance()->getProviders()->detect($trackingNumber);
    }

    /**
     * The customer-facing tracking page, deep-linked to this order when one is given.
     */
    public function trackingPageUrl(Order|int|null $order = null): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $order = $this->resolveOrder($order);

        return $settings->getTrackingPageUrl($order?->uid);
    }

    /**
     * What to call an order in front of a customer.
     *
     * An order has three identifiers and any of them can be empty depending on how it was
     * created, so there is one place that decides, and everything customer-facing uses it.
     */
    public function orderNumber(Order|int|null $order): string
    {
        $order = $this->resolveOrder($order);

        if ($order === null) {
            return '';
        }

        return self::numberFor($order);
    }

    public static function numberFor(Order $order): string
    {
        foreach ([$order->reference, $order->shortNumber, $order->number, $order->id] as $candidate) {
            $candidate = trim((string)$candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Delivery statuses as `value => label`.
     *
     * @return array<string, string>
     */
    public function statuses(): array
    {
        return Shipment::statuses();
    }

    private function orderId(Order|int|null $order): ?int
    {
        if ($order instanceof Order) {
            return (int)$order->id;
        }

        return is_int($order) && $order > 0 ? $order : null;
    }

    private function resolveOrder(Order|int|null $order): ?Order
    {
        if ($order instanceof Order) {
            return $order;
        }

        $orderId = $this->orderId($order);

        return $orderId !== null ? Plugin::getInstance()->getShipments()->getOrderById($orderId) : null;
    }
}
