<?php

namespace justinholtweb\trackr\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use DateTime;
use justinholtweb\trackr\Plugin;

/**
 * One tracked shipment against a Commerce order.
 */
class Shipment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_EXCEPTION = 'exception';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_CP = 'cp';
    public const SOURCE_CSV = 'csv';
    public const SOURCE_API = 'api';
    public const SOURCE_CONSOLE = 'console';

    public ?int $id = null;
    public ?int $orderId = null;
    public ?int $storeId = null;
    public string $shipmentKey = '';
    public ?string $providerHandle = null;
    public ?string $providerName = null;
    public ?string $trackingNumber = null;
    public ?string $trackingUrl = null;
    public ?string $service = null;
    public ?DateTime $shipDate = null;
    public string $status = self::STATUS_IN_TRANSIT;
    public ?string $statusDetail = null;
    public ?DateTime $dateStatusUpdated = null;
    public ?DateTime $dateDelivered = null;
    public int $shippedQty = 0;
    public ?string $note = null;
    public string $source = self::SOURCE_CP;
    public bool $customerNotified = false;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * @var array<int, array{lineItemId: int, qty: int}>
     */
    private array $_items = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct($config = [])
    {
        foreach (['shipDate', 'dateStatusUpdated', 'dateDelivered', 'dateCreated', 'dateUpdated'] as $attribute) {
            if (isset($config[$attribute]) && !$config[$attribute] instanceof DateTime) {
                // Rows come back as naive UTC strings; DateTimeHelper reads them as UTC unless
                // told otherwise, which is exactly right here.
                $config[$attribute] = DateTimeHelper::toDateTime($config[$attribute]) ?: null;
            }
        }

        parent::__construct($config);
    }

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_IN_TRANSIT => 'In transit',
            self::STATUS_OUT_FOR_DELIVERY => 'Out for delivery',
            self::STATUS_DELIVERED => 'Delivered',
            self::STATUS_EXCEPTION => 'Exception',
            self::STATUS_RETURNED => 'Returned',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    public function setItems(mixed $value): void
    {
        if (is_string($value)) {
            $value = $value !== '' ? Json::decodeIfJson($value) : [];
        }

        $items = [];

        foreach ((array)$value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $lineItemId = (int)($item['lineItemId'] ?? $item['line_item_id'] ?? 0);
            $qty = (int)($item['qty'] ?? $item['quantity'] ?? 0);

            if ($lineItemId <= 0 || $qty <= 0) {
                continue;
            }

            $items[] = ['lineItemId' => $lineItemId, 'qty' => $qty];
        }

        $this->_items = $items;
    }

    /**
     * @return array<int, array{lineItemId: int, qty: int}>
     */
    public function getItems(): array
    {
        return $this->_items;
    }

    /**
     * True when the shipment names specific line items rather than covering the whole order.
     */
    public function isItemLevel(): bool
    {
        return $this->_items !== [];
    }

    /**
     * Units covered by this shipment. An order-level shipment reports the quantity that was
     * recorded against it when it was created.
     */
    public function getQty(): int
    {
        if ($this->_items === []) {
            return $this->shippedQty;
        }

        return array_sum(array_column($this->_items, 'qty'));
    }

    public function getProvider(): ?Provider
    {
        if ($this->providerHandle === null) {
            return null;
        }

        return Plugin::getInstance()->getProviders()->getProviderByHandle($this->providerHandle);
    }

    /**
     * What to call the carrier on screen. The stored snapshot wins over the registry so a
     * shipment made under a since-renamed provider still reads the way it was recorded.
     */
    public function getProviderLabel(): string
    {
        if ($this->providerName !== null && $this->providerName !== '') {
            return $this->providerName;
        }

        $provider = $this->getProvider();

        return $provider->name ?? ($this->providerHandle ?? '');
    }

    /**
     * The public tracking URL. Never guessed here — `services\Providers::trackingUrl()` is the
     * single place a URL is resolved, so the CP link, the email widget and the tracking page
     * cannot disagree.
     */
    public function getTrackingUrl(): ?string
    {
        return Plugin::getInstance()->getProviders()->trackingUrlForShipment($this);
    }

    public function getStatusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    /**
     * Craft's status-dot colour for this status.
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_DELIVERED => 'green',
            self::STATUS_OUT_FOR_DELIVERY => 'blue',
            self::STATUS_IN_TRANSIT => 'blue',
            self::STATUS_EXCEPTION, self::STATUS_RETURNED => 'red',
            self::STATUS_CANCELLED => 'black',
            default => 'orange',
        };
    }

    public function getIsDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    /**
     * Whether the shipment is still expected to move.
     */
    public function getIsActive(): bool
    {
        return !in_array($this->status, [
            self::STATUS_DELIVERED,
            self::STATUS_CANCELLED,
            self::STATUS_RETURNED,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWidgetArray(): array
    {
        return [
            'provider' => $this->getProviderLabel(),
            'trackingNumber' => $this->trackingNumber,
            'trackingUrl' => $this->getTrackingUrl(),
            'service' => $this->service,
            'status' => $this->status,
            'statusLabel' => $this->getStatusLabel(),
            'shipDate' => $this->shipDate?->format('c'),
            'dateDelivered' => $this->dateDelivered?->format('c'),
            'note' => $this->note,
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), ['items']);
    }
}
