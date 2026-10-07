<?php

namespace justinholtweb\trackr\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\OrderHistory;
use craft\commerce\models\OrderStatus;
use craft\commerce\Plugin as Commerce;
use justinholtweb\trackr\models\OrderState;
use justinholtweb\trackr\models\Shipment;
use justinholtweb\trackr\Plugin;
use Throwable;

/**
 * Moving Commerce orders along as their shipments are recorded.
 *
 * Everything here is best-effort: a status that has been deleted, or a save blocked by another
 * plugin's handler, must never stop tracking being recorded. The tracking number is the thing
 * the customer needs; the status is bookkeeping.
 */
class Statuses extends Component
{
    /**
     * Advance an order for a shipment.
     *
     * @return bool whether the order's status actually changed
     */
    public function applyForOrder(Order $order, Shipment $shipment, OrderState $state, bool $addNote = true): bool
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $handle = $this->targetStatusHandle($state, $plugin->isPro());
        $status = $handle !== null ? $this->getStatusByHandle($handle, $order) : null;

        if ($status === null) {
            if ($addNote && $settings->addOrderHistoryNote) {
                $this->addHistoryNote($order, $shipment);
            }

            return false;
        }

        if ((int)$order->orderStatusId === (int)$status->id) {
            // Craft writes no order history — and sends no status email — when the status has
            // not moved. A tracking note still belongs on the order.
            if ($addNote && $settings->addOrderHistoryNote) {
                $this->addHistoryNote($order, $shipment);
            }

            return false;
        }

        $order->orderStatusId = (int)$status->id;
        $order->message = $this->noteFor($shipment);

        try {
            $saved = Craft::$app->getElements()->saveElement($order, false);
        } catch (Throwable $e) {
            Craft::error('Trackr could not move order ' . $order->id . ': ' . $e->getMessage(), __METHOD__);
            $saved = false;
        }

        if (!$saved) {
            Plugin::getInstance()->getLog()->write(
                'status.failed',
                Craft::t('trackr', 'Could not move order {order} to {status}', [
                    'order' => $order->reference ?: $order->id,
                    'status' => $status->name,
                ]),
                ['orderId' => (int)$order->id, 'level' => 'warning']
            );
        }

        return $saved;
    }

    /**
     * Write a tracking note onto the order's history without pretending the status changed.
     *
     * `Order::_saveOrderHistory()` early-returns when the status is unchanged, so a note that
     * must not masquerade as a status change has to go straight to the history service.
     */
    public function addHistoryNote(Order $order, Shipment $shipment): bool
    {
        if (!$order->id || !$order->orderStatusId) {
            return false;
        }

        try {
            $history = new OrderHistory();
            $history->orderId = (int)$order->id;
            $history->prevStatusId = (int)$order->orderStatusId;
            $history->newStatusId = (int)$order->orderStatusId;
            $history->message = $this->noteFor($shipment);

            // Commerce marks `userId` required. Tracking arrives from CSV imports, the push API
            // and console commands, none of which have a logged-in user — so the note falls back
            // to the order's customer, and failing that skips validation rather than being lost.
            $history->userId = Craft::$app->getUser()->getIdentity()->id ?? $order->customerId;

            return Commerce::getInstance()
                ->getOrderHistories()
                ->saveOrderHistory($history, $history->userId !== null);
        } catch (Throwable $e) {
            Craft::error('Trackr could not write an order history note: ' . $e->getMessage(), __METHOD__);

            return false;
        }
    }

    /**
     * The status an order in this state belongs in, or null to leave it alone.
     */
    public function targetStatusHandle(OrderState $state, bool $isPro): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($isPro && $state->getIsDelivered() && $settings->deliveredStatusHandle !== '') {
            return $settings->deliveredStatusHandle;
        }

        if ($state->fullyShipped) {
            return $settings->shippedStatusHandle !== '' ? $settings->shippedStatusHandle : null;
        }

        if (!$state->getHasShipped()) {
            return null;
        }

        // Partly shipped. Lite has no concept of it, so a shipment simply marks the order shipped.
        if (!$isPro || !$settings->partialShipmentsEnabled) {
            return $settings->shippedStatusHandle !== '' ? $settings->shippedStatusHandle : null;
        }

        return $settings->partiallyShippedStatusHandle !== '' ? $settings->partiallyShippedStatusHandle : null;
    }

    public function getStatusByHandle(string $handle, ?Order $order = null): ?OrderStatus
    {
        if ($handle === '' || !Plugin::commerceIsReady()) {
            return null;
        }

        try {
            $storeId = $order->storeId ?? Commerce::getInstance()->getStores()->getCurrentStore()->id;

            return Commerce::getInstance()->getOrderStatuses()->getOrderStatusByHandle($handle, $storeId);
        } catch (Throwable $e) {
            Craft::error('Trackr could not read order status ' . $handle . ': ' . $e->getMessage(), __METHOD__);

            return null;
        }
    }

    /**
     * Commerce order statuses as `handle => name`, for settings dropdowns.
     *
     * @return array<string, string>
     */
    public function getStatusOptions(): array
    {
        if (!Plugin::commerceIsReady()) {
            return [];
        }

        $options = [];

        try {
            $storeId = Commerce::getInstance()->getStores()->getCurrentStore()->id;

            foreach (Commerce::getInstance()->getOrderStatuses()->getAllOrderStatuses($storeId) as $status) {
                $options[$status->handle] = $status->name;
            }
        } catch (Throwable $e) {
            Craft::error('Trackr could not list order statuses: ' . $e->getMessage(), __METHOD__);
        }

        return $options;
    }

    /**
     * Create a Commerce order status if nothing already holds the handle.
     *
     * Merchants coming from WooCommerce expect "Shipped" and "Partially shipped" to exist; in
     * Commerce they are project config the merchant has to build by hand first. This does it for
     * them from the settings screen.
     *
     * @return array{created: bool, status: OrderStatus|null, message: string}
     */
    public function createStatus(string $handle, string $name, string $color = 'blue'): array
    {
        if (!Plugin::commerceIsReady()) {
            return ['created' => false, 'status' => null, 'message' => Craft::t('trackr', 'Commerce is not available.')];
        }

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            return [
                'created' => false,
                'status' => null,
                'message' => Craft::t('trackr', 'Order statuses are project config, which this environment does not allow changing.'),
            ];
        }

        $existing = $this->getStatusByHandle($handle);

        if ($existing !== null) {
            return ['created' => false, 'status' => $existing, 'message' => Craft::t('trackr', 'That status already exists.')];
        }

        try {
            $commerce = Commerce::getInstance();
            $storeId = $commerce->getStores()->getCurrentStore()->id;

            $status = new OrderStatus();
            $status->name = $name;
            $status->handle = $handle;
            $status->color = $color;
            $status->storeId = $storeId;
            $status->sortOrder = 99;

            if (!$commerce->getOrderStatuses()->saveOrderStatus($status, [])) {
                return [
                    'created' => false,
                    'status' => null,
                    'message' => implode(' ', array_merge(...array_values($status->getErrors()))),
                ];
            }

            return ['created' => true, 'status' => $status, 'message' => Craft::t('trackr', 'Status created.')];
        } catch (Throwable $e) {
            return ['created' => false, 'status' => null, 'message' => $e->getMessage()];
        }
    }

    private function noteFor(Shipment $shipment): string
    {
        $provider = $shipment->getProviderLabel();
        $number = $shipment->trackingNumber;

        if ($number === null) {
            return Craft::t('trackr', 'Shipment recorded ({provider}).', ['provider' => $provider ?: Craft::t('trackr', 'no carrier')]);
        }

        if ($provider === '') {
            return Craft::t('trackr', 'Shipped — tracking {number}.', ['number' => $number]);
        }

        return Craft::t('trackr', 'Shipped via {provider} — tracking {number}.', [
            'provider' => $provider,
            'number' => $number,
        ]);
    }
}
