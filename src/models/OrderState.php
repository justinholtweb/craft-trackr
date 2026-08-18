<?php

namespace justinholtweb\trackr\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;

/**
 * Trackr's rolled-up view of one order's fulfilment: how much of it has shipped, when, and
 * whether it has arrived.
 */
class OrderState extends Model
{
    public ?int $orderId = null;
    public int $shipmentCount = 0;
    public int $shippedQty = 0;
    public ?DateTime $dateFirstShipped = null;
    public ?DateTime $dateLastShipped = null;
    public ?DateTime $dateDelivered = null;
    public bool $fullyShipped = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct($config = [])
    {
        foreach (['dateFirstShipped', 'dateLastShipped', 'dateDelivered'] as $attribute) {
            if (isset($config[$attribute]) && !$config[$attribute] instanceof DateTime) {
                $config[$attribute] = DateTimeHelper::toDateTime($config[$attribute]) ?: null;
            }
        }

        // Rows carry columns this model has no use for.
        $config = array_intersect_key($config, array_flip([
            'orderId', 'shipmentCount', 'shippedQty',
            'dateFirstShipped', 'dateLastShipped', 'dateDelivered', 'fullyShipped',
        ]));

        parent::__construct($config);
    }

    public function getHasShipped(): bool
    {
        return $this->shipmentCount > 0;
    }

    public function getIsDelivered(): bool
    {
        return $this->dateDelivered !== null;
    }
}
