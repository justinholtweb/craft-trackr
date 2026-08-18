<?php

namespace justinholtweb\trackr\records;

use craft\db\ActiveRecord;
use justinholtweb\trackr\db\Table;

/**
 * @property int $id
 * @property int $orderId
 * @property int|null $storeId
 * @property string $shipmentKey
 * @property string|null $providerHandle
 * @property string|null $providerName
 * @property string|null $trackingNumber
 * @property string|null $trackingUrl
 * @property string|null $service
 * @property string|null $shipDate
 * @property string $status
 * @property string|null $statusDetail
 * @property string|null $dateStatusUpdated
 * @property string|null $dateDelivered
 * @property string|null $items
 * @property int $shippedQty
 * @property string|null $note
 * @property string $source
 * @property bool $customerNotified
 */
class ShipmentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::SHIPMENTS;
    }
}
