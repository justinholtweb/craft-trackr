<?php

namespace justinholtweb\trackr\records;

use craft\db\ActiveRecord;
use justinholtweb\trackr\db\Table;

/**
 * @property int $orderId
 * @property int $shipmentCount
 * @property int $shippedQty
 * @property string|null $dateFirstShipped
 * @property string|null $dateLastShipped
 * @property string|null $dateDelivered
 * @property bool $fullyShipped
 */
class OrderStateRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ORDERSTATE;
    }

    /**
     * @inheritdoc
     */
    public static function primaryKey(): array
    {
        return ['orderId'];
    }
}
