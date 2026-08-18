<?php

namespace justinholtweb\trackr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\trackr\models\Shipment;
use justinholtweb\trackr\Plugin;
use yii\console\ExitCode;

/**
 * Shipments from the command line.
 *
 * Craft plugin commands are not reachable through `craft help trackr` — they are listed under
 * `craft help` and run as `trackr/shipments/add`.
 */
class ShipmentsController extends Controller
{
    /**
     * Carrier handle or name, e.g. `ups` or "DHL Express".
     */
    public string $carrier = '';

    /**
     * Shipping service, e.g. "Ground".
     */
    public string $service = '';

    /**
     * Ship date, in anything `strtotime()` understands. Defaults to now.
     */
    public string $date = '';

    /**
     * Delivery status: pending, in_transit, out_for_delivery, delivered, exception, returned,
     * cancelled.
     */
    public string $status = '';

    /**
     * Email the customer.
     */
    public bool $notify = false;

    /**
     * Filter the list by delivery status.
     */
    public string $filterStatus = '';

    /**
     * How many rows to list.
     */
    public int $limit = 25;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'add' => array_merge($options, ['carrier', 'service', 'date', 'status', 'notify']),
            'list' => array_merge($options, ['filterStatus', 'limit']),
            default => $options,
        };
    }

    /**
     * Record tracking against an order.
     *
     * trackr/shipments/add CT-1042 1Z999AA10123456784 --carrier=ups
     */
    public function actionAdd(string $orderNumber, ?string $trackingNumber = null): int
    {
        $plugin = Plugin::getInstance();
        $order = $plugin->getTracking()->findOrder($orderNumber);

        if ($order === null) {
            $this->stderr("No order matches \"$orderNumber\".\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $result = $plugin->getShipments()->record($order, [
            'provider' => $this->carrier,
            'trackingNumber' => $trackingNumber,
            'service' => $this->service,
            'shipDate' => $this->date ?: null,
            'status' => $this->status ?: null,
            'source' => Shipment::SOURCE_CONSOLE,
            'notify' => $this->notify ? true : null,
        ]);

        if ($result['shipment'] === null) {
            foreach ($result['errors'] as $error) {
                $this->stderr('  ' . (is_array($error) ? implode(' ', $error) : $error) . "\n", Console::FG_RED);
            }

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $shipment = $result['shipment'];

        $this->stdout(($result['isNew'] ? 'Added ' : 'Updated ') . ($shipment->trackingNumber ?? 'shipment') . "\n", Console::FG_GREEN);
        $this->stdout('  Order:    ' . ($order->reference ?: $order->id) . "\n");
        $this->stdout('  Carrier:  ' . ($shipment->getProviderLabel() ?: '—') . "\n");
        $this->stdout('  URL:      ' . ($shipment->getTrackingUrl() ?? '—') . "\n");
        $this->stdout('  Shipped:  ' . ($result['fullyShipped'] ? 'fully' : 'partly') . "\n");

        return ExitCode::OK;
    }

    /**
     * List recent shipments.
     */
    public function actionList(): int
    {
        $shipments = Plugin::getInstance()->getShipments()->getShipments(
            ['status' => $this->filterStatus ?: null],
            max(1, $this->limit)
        );

        if ($shipments === []) {
            $this->stdout("No shipments.\n");

            return ExitCode::OK;
        }

        foreach ($shipments as $shipment) {
            $this->stdout(sprintf(
                "%-6s %-22s %-18s %-16s %s\n",
                '#' . $shipment->id,
                substr((string)$shipment->trackingNumber, 0, 22),
                substr($shipment->getProviderLabel(), 0, 18),
                $shipment->status,
                $shipment->shipDate?->format('Y-m-d') ?? '—'
            ));
        }

        $this->stdout(sprintf("\n%d shown.\n", count($shipments)));

        return ExitCode::OK;
    }

    /**
     * Move a shipment's delivery status.
     *
     * trackr/shipments/set-status 1Z999AA10123456784 delivered
     */
    public function actionSetStatus(string $trackingNumber, string $status): int
    {
        $plugin = Plugin::getInstance();

        if (!isset(Shipment::statuses()[$status])) {
            $this->stderr('Unknown status. Use one of: ' . implode(', ', array_keys(Shipment::statuses())) . "\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        foreach ($plugin->getShipments()->getShipments(['search' => $trackingNumber], 25) as $shipment) {
            if (strcasecmp((string)$shipment->trackingNumber, $trackingNumber) !== 0) {
                continue;
            }

            if ($plugin->getShipments()->updateStatus($shipment, $status)) {
                $this->stdout("Updated #{$shipment->id} to $status.\n", Console::FG_GREEN);

                return ExitCode::OK;
            }

            $this->stderr("Could not update that shipment.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stderr("No shipment with that tracking number.\n", Console::FG_RED);

        return ExitCode::DATAERR;
    }

    /**
     * Rebuild the per-order fulfilment rollup from the shipment rows.
     */
    public function actionRecalculate(?string $orderNumber = null): int
    {
        $plugin = Plugin::getInstance();
        $shipments = $plugin->getShipments();

        if ($orderNumber !== null) {
            $order = $plugin->getTracking()->findOrder($orderNumber);

            if ($order === null) {
                $this->stderr("No order matches \"$orderNumber\".\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }

            $state = $shipments->recalculateOrderState($order);
            $this->stdout("Recalculated: {$state->shippedQty} units across {$state->shipmentCount} shipments.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $orderIds = array_values(array_unique(array_map(
            static fn(Shipment $shipment) => (int)$shipment->orderId,
            $shipments->getShipments([], 10000)
        )));

        $done = 0;

        foreach ($orderIds as $orderId) {
            $order = $shipments->getOrderById($orderId);

            if ($order !== null) {
                $shipments->recalculateOrderState($order);
                $done++;
            }
        }

        $this->stdout("Recalculated $done orders.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Show what Trackr knows about one order.
     */
    public function actionShow(string $orderNumber): int
    {
        $plugin = Plugin::getInstance();
        $order = $plugin->getTracking()->findOrder($orderNumber);

        if ($order === null) {
            $this->stderr("No order matches \"$orderNumber\".\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $progress = $plugin->getShipments()->getProgress($order);

        $this->stdout('Order ' . ($order->reference ?: $order->id) . "\n", Console::FG_CYAN);
        $this->stdout(sprintf(
            "  %d of %d units shipped (%d%%)%s\n",
            $progress['shipped'],
            $progress['total'],
            $progress['percent'],
            $progress['fullyShipped'] ? ' — fully shipped' : ''
        ));

        foreach ($plugin->getShipments()->getShipmentsForOrder((int)$order->id) as $shipment) {
            $this->stdout(sprintf(
                "  · %-20s %-16s %s\n",
                $shipment->getProviderLabel() ?: '—',
                $shipment->status,
                $shipment->getTrackingUrl() ?? ($shipment->trackingNumber ?? '—')
            ));
        }

        return ExitCode::OK;
    }
}
