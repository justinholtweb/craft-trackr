<?php

namespace justinholtweb\trackr\records;

use craft\db\ActiveRecord;
use justinholtweb\trackr\db\Table;

/**
 * @property int $id
 * @property string $action
 * @property string $level
 * @property int|null $orderId
 * @property string|null $summary
 * @property string|null $message
 * @property string|null $payload
 * @property string|null $source
 * @property string|null $ip
 */
class LogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LOG;
    }
}
