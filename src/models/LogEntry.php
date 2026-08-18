<?php

namespace justinholtweb\trackr\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;

/**
 * One row of Trackr's activity log.
 */
class LogEntry extends Model
{
    public ?int $id = null;
    public string $action = '';
    public string $level = 'info';
    public ?int $orderId = null;
    public ?string $summary = null;
    public ?string $message = null;
    public ?string $payload = null;
    public ?string $source = null;
    public ?string $ip = null;
    public ?DateTime $dateCreated = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct($config = [])
    {
        if (isset($config['dateCreated']) && !$config['dateCreated'] instanceof DateTime) {
            $config['dateCreated'] = DateTimeHelper::toDateTime($config['dateCreated']) ?: null;
        }

        unset($config['dateUpdated'], $config['uid']);

        parent::__construct($config);
    }

    public function getLevelColor(): string
    {
        return match ($this->level) {
            'error' => 'red',
            'warning' => 'orange',
            default => 'green',
        };
    }
}
