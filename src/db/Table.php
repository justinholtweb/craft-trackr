<?php

namespace justinholtweb\trackr\db;

/**
 * Trackr's database tables.
 */
abstract class Table
{
    public const SHIPMENTS = '{{%trackr_shipments}}';
    public const ORDERSTATE = '{{%trackr_orderstate}}';
    public const LOG = '{{%trackr_log}}';
}
