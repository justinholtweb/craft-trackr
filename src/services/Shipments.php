<?php

namespace justinholtweb\trackr\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use DateTimeInterface;
use justinholtweb\trackr\db\Table;
use justinholtweb\trackr\events\ShipmentEvent;
use justinholtweb\trackr\models\OrderState;
use justinholtweb\trackr\models\Provider;
use justinholtweb\trackr\models\Shipment;
use justinholtweb\trackr\Plugin;
use justinholtweb\trackr\records\OrderStateRecord;
use justinholtweb\trackr\records\ShipmentRecord;
use Throwable;

/**
 * Tracked shipments against Commerce orders.
 *
 * **The invariant:** `record()` is the only place a shipment row is written. The order screen, a
 * CSV import, the push API and every console command land here, so provider resolution,
 * idempotency, the shipped-quantity maths and the order-status decision happen once and cannot
 * disagree with each other.
 */
class Shipments extends Component
{
    /**
     * @event ShipmentEvent Raised after a shipment is recorded or updated.
     */
    public const EVENT_AFTER_SAVE_SHIPMENT = 'afterSaveShipment';

    /**
     * @event ShipmentEvent Raised after a shipment's status changes.
     */
    public const EVENT_AFTER_UPDATE_STATUS = 'afterUpdateShipmentStatus';

    /**
     * Record tracking against an order.
     *
     * Re-sending the same tracking number for the same carrier updates that shipment instead of
     * creating a second one: fulfilment services retry, CSVs get re-uploaded, and staff paste
     * twice.
     *
     * @param array{
     *     provider?: string|null,
     *     providerName?: string|null,
     *     trackingNumber?: string|null,
     *     trackingUrl?: string|null,
     *     service?: string|null,
     *     shipDate?: DateTimeInterface|string|null,
     *     status?: string|null,
     *     statusDetail?: string|null,
     *     items?: array,
     *     partial?: bool,
     *     note?: string|null,
     *     source?: string,
     *     notify?: bool|null,
     * } $data
     * @return array{shipment: Shipment|null, isNew: bool, fullyShipped: bool, statusChanged: bool, errors: array}
     */
    public function record(Order $order, array $data): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $trackingNumber = trim((string)($data['trackingNumber'] ?? ''));
        $providerValue = trim((string)($data['provider'] ?? ''));

        $provider = $plugin->getProviders()->resolve($providerValue);

        if ($provider === null && $settings->autoDetectProvider) {
            $provider = $plugin->getProviders()->detect($trackingNumber);
        }

        if ($trackingNumber === '' && $provider === null) {
            return $this->failure([Craft::t('trackr', 'A shipment needs a tracking number or a carrier.')]);
        }

        $status = (string)($data['status'] ?? $settings->defaultShipmentStatus);

        if (!isset(Shipment::statuses()[$status])) {
            $status = Shipment::STATUS_IN_TRANSIT;
        }

        $shipDate = $this->toDateTime($data['shipDate'] ?? null) ?? new DateTime();
        $shipmentKey = $this->buildShipmentKey($trackingNumber, $provider?->handle ?? $providerValue, $data);

        $record = ShipmentRecord::findOne([
            'orderId' => $order->id,
            'shipmentKey' => $shipmentKey,
        ]);

        $isNew = $record === null;

        if ($isNew) {
            $record = new ShipmentRecord();
            $record->orderId = (int)$order->id;
            $record->storeId = $order->storeId;
            $record->shipmentKey = $shipmentKey;
            $record->customerNotified = false;
        }

        $record->providerHandle = $provider?->handle ?: ($providerValue !== '' ? $providerValue : null);
        // The name is snapshotted so a shipment still reads correctly after the carrier is
        // renamed or removed from settings.
        $record->providerName = $provider?->name
            ?: (($data['providerName'] ?? null) ?: ($providerValue !== '' ? $providerValue : null));
        $record->trackingNumber = $trackingNumber !== '' ? $trackingNumber : null;
        $record->trackingUrl = trim((string)($data['trackingUrl'] ?? '')) ?: null;
        $record->service = trim((string)($data['service'] ?? '')) ?: null;
        $record->shipDate = Db::prepareDateForDb($shipDate);
        $record->note = trim((string)($data['note'] ?? '')) ?: null;
        $record->source = (string)($data['source'] ?? Shipment::SOURCE_CP);

        if ($record->status !== $status || $isNew) {
            $record->dateStatusUpdated = Db::prepareDateForDb(new DateTime());
        }

        $record->status = $status;
        $record->statusDetail = trim((string)($data['statusDetail'] ?? '')) ?: null;
        $record->dateDelivered = $status === Shipment::STATUS_DELIVERED
            ? ($record->dateDelivered ?? Db::prepareDateForDb(new DateTime()))
            : null;

        $items = $this->normalizeItems($order, $data['items'] ?? [], $plugin->isPro());
        $record->items = Json::encode($items);
        $record->shippedQty = $this->decideShippedQty($order, $items, (bool)($data['partial'] ?? false), $record->id);

        if (!$record->save()) {
            Craft::error('Trackr could not save a shipment: ' . Json::encode($record->getErrors()), __METHOD__);

            return $this->failure($record->getErrors());
        }

        $shipment = $this->recordToModel($record);

        // Order state is recalculated from the rows rather than incremented, so a deleted or
        // edited shipment can never leave the order counting units that are not there.
        $state = $this->recalculateOrderState($order);
        $fullyShipped = $state->fullyShipped;

        $statusChanged = false;

        if ($settings->updateStatusOnTracking) {
            $statusChanged = $plugin->getStatuses()->applyForOrder($order, $shipment, $state);
        } elseif ($settings->addOrderHistoryNote) {
            $plugin->getStatuses()->addHistoryNote($order, $shipment);
        }

        if ($data['notify'] ?? null) {
            $plugin->getNotifications()->sendShipmentEmail($order, $shipment);
        } elseif (($data['notify'] ?? null) === null && $isNew && $settings->notifyOnShipment) {
            $plugin->getNotifications()->sendShipmentEmail($order, $shipment);
        }

        $plugin->getLog()->write(
            $isNew ? 'shipment.create' : 'shipment.update',
            sprintf(
                '%s %s on order %s',
                $shipment->getProviderLabel() ?: Craft::t('trackr', 'Shipment'),
                $shipment->trackingNumber ?? '—',
                $order->reference ?: $order->id
            ),
            [
                'orderId' => (int)$order->id,
                'source' => $record->source,
                'payload' => $settings->logPayloads ? Json::encode($shipment->toWidgetArray()) : null,
            ]
        );

        $this->trigger(self::EVENT_AFTER_SAVE_SHIPMENT, new ShipmentEvent([
            'shipment' => $shipment,
            'order' => $order,
            'isNew' => $isNew,
        ]));

        return [
            'shipment' => $shipment,
            'isNew' => $isNew,
            'fullyShipped' => $fullyShipped,
            'statusChanged' => $statusChanged,
            'errors' => [],
        ];
    }

    /**
     * Move a shipment along its delivery status without touching anything else about it.
     */
    public function updateStatus(Shipment $shipment, string $status, ?string $detail = null): bool
    {
        if (!isset(Shipment::statuses()[$status])) {
            return false;
        }

        $record = ShipmentRecord::findOne(['id' => $shipment->id]);

        if ($record === null) {
            return false;
        }

        $previous = $record->status;
        $record->status = $status;
        $record->statusDetail = $detail !== null && trim($detail) !== '' ? trim($detail) : $record->statusDetail;
        $record->dateStatusUpdated = Db::prepareDateForDb(new DateTime());

        if ($status === Shipment::STATUS_DELIVERED) {
            $record->dateDelivered ??= Db::prepareDateForDb(new DateTime());
        } else {
            $record->dateDelivered = null;
        }

        if (!$record->save()) {
            return false;
        }

        $updated = $this->recordToModel($record);
        $order = $this->getOrderById((int)$record->orderId);

        if ($order !== null) {
            $state = $this->recalculateOrderState($order);

            if (Plugin::getInstance()->getSettings()->updateStatusOnTracking) {
                Plugin::getInstance()->getStatuses()->applyForOrder($order, $updated, $state, false);
            }

            if (
                $status === Shipment::STATUS_DELIVERED
                && $previous !== Shipment::STATUS_DELIVERED
                && Plugin::getInstance()->getSettings()->notifyOnDelivery
            ) {
                Plugin::getInstance()->getNotifications()->sendDeliveryEmail($order, $updated);
            }
        }

        Plugin::getInstance()->getLog()->write('shipment.status', sprintf(
            '%s → %s',
            $updated->trackingNumber ?? Craft::t('trackr', 'Shipment'),
            $updated->getStatusLabel()
        ), ['orderId' => (int)$record->orderId]);

        $this->trigger(self::EVENT_AFTER_UPDATE_STATUS, new ShipmentEvent([
            'shipment' => $updated,
            'order' => $order,
            'isNew' => false,
        ]));

        return true;
    }

    /**
     * @return Shipment[]
     */
    public function getShipmentsForOrder(int $orderId): array
    {
        $rows = $this->createQuery()
            ->where(['orderId' => $orderId])
            ->orderBy(['shipDate' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return array_map(fn(array $row) => new Shipment($row), $rows);
    }

    public function getShipmentById(int $id): ?Shipment
    {
        $row = $this->createQuery()->where(['id' => $id])->one();

        return $row ? new Shipment($row) : null;
    }

    public function getShipmentByUid(string $uid): ?Shipment
    {
        $row = $this->createQuery()->where(['uid' => $uid])->one();

        return $row ? new Shipment($row) : null;
    }

    /**
     * Shipments across all orders, for the CP index.
     *
     * @param array{search?: string|null, status?: string|null, provider?: string|null} $filters
     * @return Shipment[]
     */
    public function getShipments(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $query = $this->buildFilteredQuery($filters)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset);

        return array_map(fn(array $row) => new Shipment($row), $query->all());
    }

    /**
     * @param array{search?: string|null, status?: string|null, provider?: string|null} $filters
     */
    public function getShipmentCount(array $filters = []): int
    {
        return (int)$this->buildFilteredQuery($filters)->count('[[id]]');
    }

    /**
     * How many shipments sit in each delivery status, for the index tabs.
     *
     * @return array<string, int>
     */
    public function getStatusCounts(): array
    {
        $counts = array_fill_keys(array_keys(Shipment::statuses()), 0);

        $rows = (new Query())
            ->select(['status', 'total' => 'COUNT(*)'])
            ->from(Table::SHIPMENTS)
            ->groupBy(['status'])
            ->all();

        foreach ($rows as $row) {
            $counts[$row['status']] = (int)$row['total'];
        }

        return $counts;
    }

    public function deleteShipmentById(int $id): bool
    {
        $record = ShipmentRecord::findOne(['id' => $id]);

        if ($record === null) {
            return false;
        }

        $orderId = (int)$record->orderId;
        $tracking = $record->trackingNumber;

        if (!$record->delete()) {
            return false;
        }

        $order = $this->getOrderById($orderId);

        if ($order !== null) {
            $this->recalculateOrderState($order);
        }

        Plugin::getInstance()->getLog()->write(
            'shipment.delete',
            Craft::t('trackr', 'Deleted tracking {number}', ['number' => $tracking ?? '—']),
            ['orderId' => $orderId, 'level' => 'warning']
        );

        return true;
    }

    /**
     * Trackr's rolled-up view of an order. Always returns a model, even for an order that has
     * never shipped, so templates never have to null-check it.
     */
    public function getOrderState(int $orderId): OrderState
    {
        $row = (new Query())
            ->from(Table::ORDERSTATE)
            ->where(['orderId' => $orderId])
            ->one();

        return new OrderState($row ?: ['orderId' => $orderId]);
    }

    /**
     * Recompute an order's fulfilment state from its shipment rows.
     */
    public function recalculateOrderState(Order $order): OrderState
    {
        $shipments = $this->getShipmentsForOrder((int)$order->id);

        $shippedQty = 0;
        $firstShipped = null;
        $lastShipped = null;
        $deliveredDates = [];
        $countsTowardsShipping = 0;

        foreach ($shipments as $shipment) {
            if ($shipment->status === Shipment::STATUS_CANCELLED) {
                continue;
            }

            $countsTowardsShipping++;
            $shippedQty += $shipment->getQty();

            if ($shipment->shipDate !== null) {
                if ($firstShipped === null || $shipment->shipDate < $firstShipped) {
                    $firstShipped = $shipment->shipDate;
                }

                if ($lastShipped === null || $shipment->shipDate > $lastShipped) {
                    $lastShipped = $shipment->shipDate;
                }
            }

            if ($shipment->status === Shipment::STATUS_DELIVERED) {
                $deliveredDates[] = $shipment->dateDelivered ?? $shipment->dateStatusUpdated;
            }
        }

        $totalQty = $this->getShippableQty($order);
        $fullyShipped = $countsTowardsShipping > 0 && ($totalQty === 0 || $shippedQty >= $totalQty);

        // The order has only arrived once every live shipment has.
        $allDelivered = $countsTowardsShipping > 0 && count($deliveredDates) === $countsTowardsShipping;
        $dateDelivered = $allDelivered && $deliveredDates !== [] ? max(array_filter($deliveredDates)) : null;

        $record = OrderStateRecord::findOne(['orderId' => $order->id]) ?? new OrderStateRecord([
            'orderId' => (int)$order->id,
        ]);

        $record->shipmentCount = $countsTowardsShipping;
        $record->shippedQty = $shippedQty;
        $record->dateFirstShipped = $firstShipped ? Db::prepareDateForDb($firstShipped) : null;
        $record->dateLastShipped = $lastShipped ? Db::prepareDateForDb($lastShipped) : null;
        $record->dateDelivered = $dateDelivered ? Db::prepareDateForDb($dateDelivered) : null;
        $record->fullyShipped = $fullyShipped;

        if ($countsTowardsShipping === 0 && !$record->getIsNewRecord()) {
            $record->delete();

            return new OrderState(['orderId' => (int)$order->id]);
        }

        if ($countsTowardsShipping === 0) {
            return new OrderState(['orderId' => (int)$order->id]);
        }

        $record->save();

        return $this->getOrderState((int)$order->id);
    }

    /**
     * Units on the order that can actually be shipped. Digital goods and anything else
     * non-shippable are left out, or an order could never reach "fully shipped".
     */
    public function getShippableQty(Order $order): int
    {
        $qty = 0;

        foreach ($order->getLineItems() as $lineItem) {
            if (method_exists($lineItem, 'getIsShippable') && !$lineItem->getIsShippable()) {
                continue;
            }

            $qty += (int)$lineItem->qty;
        }

        return $qty;
    }

    /**
     * Fulfilment progress for an order, for progress bars and the Twig API.
     *
     * @return array{shipped: int, total: int, remaining: int, percent: int, fullyShipped: bool, shipmentCount: int}
     */
    public function getProgress(Order $order): array
    {
        $state = $this->getOrderState((int)$order->id);
        $total = $this->getShippableQty($order);
        $shipped = min($state->shippedQty, $total ?: $state->shippedQty);

        return [
            'shipped' => $shipped,
            'total' => $total,
            'remaining' => max(0, $total - $shipped),
            'percent' => $total > 0 ? (int)round(($shipped / $total) * 100) : ($state->fullyShipped ? 100 : 0),
            'fullyShipped' => $state->fullyShipped,
            'shipmentCount' => $state->shipmentCount,
        ];
    }

    /**
     * Units of each line item that are still unaccounted for, for the item-level picker (Pro).
     *
     * @return array<int, int> lineItemId => remaining qty
     */
    public function getUnshippedLineItemQtys(Order $order, ?int $excludeShipmentId = null): array
    {
        $remaining = [];

        foreach ($order->getLineItems() as $lineItem) {
            if (method_exists($lineItem, 'getIsShippable') && !$lineItem->getIsShippable()) {
                continue;
            }

            $remaining[(int)$lineItem->id] = (int)$lineItem->qty;
        }

        foreach ($this->getShipmentsForOrder((int)$order->id) as $shipment) {
            if ($shipment->id === $excludeShipmentId || $shipment->status === Shipment::STATUS_CANCELLED) {
                continue;
            }

            foreach ($shipment->getItems() as $item) {
                if (isset($remaining[$item['lineItemId']])) {
                    $remaining[$item['lineItemId']] = max(0, $remaining[$item['lineItemId']] - $item['qty']);
                }
            }
        }

        return $remaining;
    }

    /**
     * Every shipment still expected to move, for status polling and reporting.
     *
     * @return Shipment[]
     */
    public function getActiveShipments(int $limit = 500): array
    {
        $rows = $this->createQuery()
            ->where(['status' => [Shipment::STATUS_PENDING, Shipment::STATUS_IN_TRANSIT, Shipment::STATUS_OUT_FOR_DELIVERY]])
            ->orderBy(['shipDate' => SORT_ASC])
            ->limit($limit)
            ->all();

        return array_map(fn(array $row) => new Shipment($row), $rows);
    }

    public function getOrderById(int $orderId): ?Order
    {
        $order = Order::find()->id($orderId)->status(null)->one();

        return $order instanceof Order ? $order : null;
    }

    /**
     * The identity of a shipment: carrier plus tracking number. A shipment with no tracking
     * number at all falls back to a hash of its contents so two hand-written "customer pickup"
     * rows on one order stay distinct.
     *
     * @param array<string, mixed> $data
     */
    public function buildShipmentKey(string $trackingNumber, string $provider, array $data = []): string
    {
        $trackingNumber = trim($trackingNumber);

        if ($trackingNumber !== '') {
            return substr(Provider::normalizeKey($provider) . '|' . strtolower($trackingNumber), 0, 255);
        }

        return 'noref|' . md5(Json::encode([
            Provider::normalizeKey($provider),
            $data['service'] ?? '',
            $data['note'] ?? '',
            $data['items'] ?? [],
            microtime(true),
        ]));
    }

    private function buildFilteredQuery(array $filters): Query
    {
        $query = $this->createQuery();

        $search = trim((string)($filters['search'] ?? ''));

        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->andWhere([
                'or',
                ['like', 'trackingNumber', $term, false],
                ['like', 'providerName', $term, false],
                ['like', 'service', $term, false],
                ['like', 'note', $term, false],
            ]);
        }

        if (($filters['status'] ?? '') !== '' && $filters['status'] !== null) {
            $query->andWhere(['status' => $filters['status']]);
        }

        if (($filters['provider'] ?? '') !== '' && $filters['provider'] !== null) {
            $query->andWhere(['providerHandle' => $filters['provider']]);
        }

        if (!empty($filters['orderId'])) {
            $query->andWhere(['orderId' => (int)$filters['orderId']]);
        }

        return $query;
    }

    private function createQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'orderId', 'storeId', 'shipmentKey', 'providerHandle', 'providerName',
                'trackingNumber', 'trackingUrl', 'service', 'shipDate', 'status', 'statusDetail',
                'dateStatusUpdated', 'dateDelivered', 'items', 'shippedQty', 'note', 'source',
                'customerNotified', 'dateCreated', 'dateUpdated', 'uid',
            ])
            ->from(Table::SHIPMENTS);
    }

    private function recordToModel(ShipmentRecord $record): Shipment
    {
        return new Shipment($record->getAttributes());
    }

    /**
     * Keep only line items that belong to this order, and never claim more units than the line
     * item has: a CSV or an API client can say anything.
     *
     * @return array<int, array{lineItemId: int, qty: int}>
     */
    private function normalizeItems(Order $order, mixed $items, bool $isPro): array
    {
        if (!$isPro || !is_array($items) || $items === []) {
            return [];
        }

        $available = [];

        foreach ($order->getLineItems() as $lineItem) {
            $available[(int)$lineItem->id] = (int)$lineItem->qty;
        }

        $out = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $lineItemId = (int)($item['lineItemId'] ?? $item['line_item_id'] ?? $item['id'] ?? 0);
            $qty = (int)($item['qty'] ?? $item['quantity'] ?? 0);

            if (!isset($available[$lineItemId]) || $qty <= 0) {
                continue;
            }

            $out[] = ['lineItemId' => $lineItemId, 'qty' => min($qty, $available[$lineItemId])];
        }

        return $out;
    }

    /**
     * How many units this shipment accounts for.
     *
     * An item-level shipment counts its items. A shipment flagged partial counts nothing, so the
     * order stays open until the rest is entered. Anything else is taken to cover whatever has
     * not shipped yet — which is what a merchant means by pasting one tracking number onto an
     * order and expecting it to read as shipped.
     *
     * @param array<int, array{lineItemId: int, qty: int}> $items
     */
    private function decideShippedQty(Order $order, array $items, bool $partial, ?int $excludeShipmentId): int
    {
        if ($items !== []) {
            return array_sum(array_column($items, 'qty'));
        }

        if ($partial && Plugin::getInstance()->isPro()) {
            return 0;
        }

        $total = $this->getShippableQty($order);
        $alreadyShipped = 0;

        foreach ($this->getShipmentsForOrder((int)$order->id) as $existing) {
            if ($existing->id === $excludeShipmentId || $existing->status === Shipment::STATUS_CANCELLED) {
                continue;
            }

            $alreadyShipped += $existing->getQty();
        }

        return max(0, $total - $alreadyShipped);
    }

    private function toDateTime(mixed $value): ?DateTime
    {
        if ($value instanceof DateTime) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTime::createFromInterface($value);
        }

        if (is_array($value)) {
            $value = \craft\helpers\DateTimeHelper::toDateTime($value, true) ?: null;

            return $value instanceof DateTime ? $value : null;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            // A bare date from a CSV is the merchant's local date, not UTC midnight.
            $date = \craft\helpers\DateTimeHelper::toDateTime(trim($value), true);
        } catch (Throwable) {
            return null;
        }

        return $date instanceof DateTime ? $date : null;
    }

    /**
     * @param array<int|string, mixed> $errors
     * @return array{shipment: null, isNew: false, fullyShipped: false, statusChanged: false, errors: array}
     */
    private function failure(array $errors): array
    {
        return [
            'shipment' => null,
            'isNew' => false,
            'fullyShipped' => false,
            'statusChanged' => false,
            'errors' => $errors,
        ];
    }
}
