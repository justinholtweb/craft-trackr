<?php

namespace justinholtweb\trackr\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\trackr\db\Table;

/**
 * Trackr install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::LOG);
        $this->dropTableIfExists(Table::ORDERSTATE);
        $this->dropTableIfExists(Table::SHIPMENTS);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(Table::SHIPMENTS, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'storeId' => $this->integer(),
            // Identity is the provider plus the tracking number. Every writer — the CP, a CSV
            // row, the push API, a console command — lands on the same key, so re-sending the
            // same tracking number updates one shipment instead of creating a second.
            'shipmentKey' => $this->string(255)->notNull(),
            'providerHandle' => $this->string(64),
            // Snapshot of the name at the time of writing, so a provider that is later renamed
            // or removed from settings still reads correctly on old orders.
            'providerName' => $this->string(255),
            'trackingNumber' => $this->string(255),
            // Only set when the merchant overrode the resolved URL for this one shipment.
            'trackingUrl' => $this->text(),
            'service' => $this->string(255),
            'shipDate' => $this->dateTime(),
            'status' => $this->string(32)->notNull()->defaultValue('in_transit'),
            'statusDetail' => $this->string(255),
            'dateStatusUpdated' => $this->dateTime(),
            'dateDelivered' => $this->dateTime(),
            // [{lineItemId, qty}, …] when the shipment is item-level (Pro), [] when it covers
            // the whole order.
            'items' => $this->text(),
            'shippedQty' => $this->integer()->notNull()->defaultValue(0),
            'note' => $this->text(),
            'source' => $this->string(32)->notNull()->defaultValue('cp'),
            'customerNotified' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::ORDERSTATE, [
            'orderId' => $this->integer()->notNull(),
            'shipmentCount' => $this->integer()->notNull()->defaultValue(0),
            'shippedQty' => $this->integer()->notNull()->defaultValue(0),
            'dateFirstShipped' => $this->dateTime(),
            'dateLastShipped' => $this->dateTime(),
            'dateDelivered' => $this->dateTime(),
            'fullyShipped' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[orderId]])',
        ]);

        $this->createTable(Table::LOG, [
            'id' => $this->primaryKey(),
            'action' => $this->string(32)->notNull(),
            'level' => $this->string(16)->notNull()->defaultValue('info'),
            'orderId' => $this->integer(),
            'summary' => $this->string(255),
            'message' => $this->text(),
            'payload' => $this->mediumText(),
            'source' => $this->string(32),
            'ip' => $this->string(45),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::SHIPMENTS, ['orderId'], false);
        $this->createIndex(null, Table::SHIPMENTS, ['trackingNumber'], false);
        $this->createIndex(null, Table::SHIPMENTS, ['status'], false);
        // The idempotency guarantee: the same tracking number cannot land on an order twice.
        $this->createIndex(null, Table::SHIPMENTS, ['orderId', 'shipmentKey'], true);

        $this->createIndex(null, Table::LOG, ['action'], false);
        $this->createIndex(null, Table::LOG, ['level'], false);
        $this->createIndex(null, Table::LOG, ['dateCreated'], false);
    }

    private function addForeignKeys(): void
    {
        // Orders are elements; deleting one takes its shipments and state with it.
        $this->addForeignKey(null, Table::SHIPMENTS, ['orderId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::ORDERSTATE, ['orderId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
    }
}
