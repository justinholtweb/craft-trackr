<?php

namespace justinholtweb\trackr\events;

use craft\commerce\elements\Order;
use justinholtweb\trackr\models\Shipment;
use yii\base\Event;

/**
 * Raised when a shipment is written or its delivery status moves.
 */
class ShipmentEvent extends Event
{
    public ?Shipment $shipment = null;
    public ?Order $order = null;
    public bool $isNew = false;
}
