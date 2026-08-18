<?php

namespace justinholtweb\trackr\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\trackr\models\Shipment;
use justinholtweb\trackr\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Shipments in the control panel.
 */
class ShipmentsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('trackr-viewShipments');

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $filters = [
            'search' => $request->getParam('search'),
            'status' => $request->getParam('status'),
            'provider' => $request->getParam('provider'),
        ];

        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = 100;

        $shipments = $plugin->getShipments()->getShipments($filters, $perPage, ($page - 1) * $perPage);
        $total = $plugin->getShipments()->getShipmentCount($filters);

        return $this->renderTemplate('trackr/shipments/_index', [
            'shipments' => $shipments,
            'orders' => $this->ordersFor($shipments),
            'filters' => $filters,
            'statusCounts' => $plugin->getShipments()->getStatusCounts(),
            'providerOptions' => $plugin->getProviders()->getSelectOptions(),
            'statusOptions' => $plugin->shipmentStatusOptions(),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'canManage' => Craft::$app->getUser()->checkPermission('trackr-manageShipments'),
            'isPro' => $plugin->isPro(),
        ]);
    }

    public function actionDetail(int $shipmentId): Response
    {
        $plugin = Plugin::getInstance();
        $shipment = $plugin->getShipments()->getShipmentById($shipmentId);

        if ($shipment === null) {
            throw new NotFoundHttpException('Shipment not found');
        }

        $order = $plugin->getShipments()->getOrderById((int)$shipment->orderId);

        return $this->renderTemplate('trackr/shipments/_detail', [
            'shipment' => $shipment,
            'order' => $order,
            'lineItems' => $order ? $this->lineItemIndex($order) : [],
            'providerOptions' => $plugin->getProviders()->getSelectOptions(),
            'statusOptions' => $plugin->shipmentStatusOptions(),
            'canManage' => Craft::$app->getUser()->checkPermission('trackr-manageShipments'),
            'isPro' => $plugin->isPro(),
        ]);
    }

    /**
     * Add or update tracking. Everything — the order screen, the shipment detail screen, the
     * index's quick-add — posts here, and here goes through `Shipments::record()`.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('trackr-manageShipments');

        $request = Craft::$app->getRequest();
        $orderId = (int)$request->getRequiredBodyParam('orderId');

        $order = Plugin::getInstance()->getShipments()->getOrderById($orderId);

        if ($order === null) {
            return $this->failure(Craft::t('trackr', 'Order not found.'));
        }

        $result = Plugin::getInstance()->getShipments()->record($order, [
            'provider' => $request->getBodyParam('provider'),
            'trackingNumber' => $request->getBodyParam('trackingNumber'),
            'trackingUrl' => $request->getBodyParam('trackingUrl'),
            'service' => $request->getBodyParam('service'),
            'shipDate' => $request->getBodyParam('shipDate'),
            'status' => $request->getBodyParam('status'),
            'note' => $request->getBodyParam('note'),
            'items' => $this->postedItems($request->getBodyParam('items')),
            'partial' => (bool)$request->getBodyParam('partial'),
            'source' => Shipment::SOURCE_CP,
            'notify' => $request->getBodyParam('notify') !== null ? (bool)$request->getBodyParam('notify') : null,
        ]);

        if ($result['shipment'] === null) {
            $errors = $result['errors'];
            $message = is_array($errors) && $errors !== []
                ? (is_array(reset($errors)) ? implode(' ', reset($errors)) : (string)reset($errors))
                : Craft::t('trackr', 'Couldn’t save the shipment.');

            return $this->failure($message);
        }

        return $this->success(
            $result['isNew']
                ? Craft::t('trackr', 'Tracking added.')
                : Craft::t('trackr', 'Tracking updated.'),
            ['shipmentId' => $result['shipment']->id, 'fullyShipped' => $result['fullyShipped']]
        );
    }

    public function actionUpdateStatus(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('trackr-manageShipments');

        $request = Craft::$app->getRequest();
        $shipmentId = (int)$request->getRequiredBodyParam('shipmentId');
        $status = (string)$request->getRequiredBodyParam('status');

        $shipment = Plugin::getInstance()->getShipments()->getShipmentById($shipmentId);

        if ($shipment === null) {
            return $this->failure(Craft::t('trackr', 'Shipment not found.'));
        }

        $updated = Plugin::getInstance()->getShipments()->updateStatus(
            $shipment,
            $status,
            $request->getBodyParam('statusDetail')
        );

        return $updated
            ? $this->success(Craft::t('trackr', 'Status updated.'))
            : $this->failure(Craft::t('trackr', 'Couldn’t update the status.'));
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('trackr-manageShipments');

        $shipmentId = (int)Craft::$app->getRequest()->getRequiredBodyParam('shipmentId');

        return Plugin::getInstance()->getShipments()->deleteShipmentById($shipmentId)
            ? $this->success(Craft::t('trackr', 'Tracking deleted.'))
            : $this->failure(Craft::t('trackr', 'Couldn’t delete that shipment.'));
    }

    /**
     * Email the customer this shipment's tracking on demand (Pro).
     */
    public function actionNotify(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('trackr-manageShipments');

        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            return $this->failure(Craft::t('trackr', 'Tracking emails are a Pro feature.'));
        }

        $shipmentId = (int)Craft::$app->getRequest()->getRequiredBodyParam('shipmentId');
        $shipment = $plugin->getShipments()->getShipmentById($shipmentId);

        if ($shipment === null) {
            return $this->failure(Craft::t('trackr', 'Shipment not found.'));
        }

        $order = $plugin->getShipments()->getOrderById((int)$shipment->orderId);

        if ($order === null) {
            return $this->failure(Craft::t('trackr', 'Order not found.'));
        }

        return $plugin->getNotifications()->sendShipmentEmail($order, $shipment)
            ? $this->success(Craft::t('trackr', 'Email sent.'))
            : $this->failure(Craft::t('trackr', 'Couldn’t send that email — check the log.'));
    }

    /**
     * Item-level rows post as `items[lineItemId] = qty`; a zero means "not in this shipment".
     *
     * @return array<int, array{lineItemId: int, qty: int}>
     */
    private function postedItems(mixed $posted): array
    {
        if (is_string($posted)) {
            $posted = Json::decodeIfJson($posted);
        }

        if (!is_array($posted)) {
            return [];
        }

        $items = [];

        foreach ($posted as $lineItemId => $qty) {
            if (is_array($qty)) {
                $lineItemId = $qty['lineItemId'] ?? $lineItemId;
                $qty = $qty['qty'] ?? 0;
            }

            if ((int)$qty > 0) {
                $items[] = ['lineItemId' => (int)$lineItemId, 'qty' => (int)$qty];
            }
        }

        return $items;
    }

    /**
     * @param \justinholtweb\trackr\models\Shipment[] $shipments
     * @return array<int, Order>
     */
    private function ordersFor(array $shipments): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map(
            static fn(Shipment $shipment) => $shipment->orderId,
            $shipments
        ))));

        if ($orderIds === []) {
            return [];
        }

        $orders = [];

        foreach (Order::find()->id($orderIds)->status(null)->limit(null)->all() as $order) {
            $orders[$order->id] = $order;
        }

        return $orders;
    }

    /**
     * @return array<int, array{description: string, qty: int, sku: string}>
     */
    private function lineItemIndex(Order $order): array
    {
        $index = [];

        foreach ($order->getLineItems() as $lineItem) {
            $index[(int)$lineItem->id] = [
                'description' => (string)$lineItem->getDescription(),
                'qty' => (int)$lineItem->qty,
                'sku' => (string)$lineItem->getSku(),
            ];
        }

        return $index;
    }

    private function success(string $message, array $data = []): Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess($message, $data);
        }

        return $this->redirectToPostedUrl(null, 'trackr/shipments');
    }

    private function failure(string $message): Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asFailure($message);
        }

        Craft::$app->getSession()->setError($message);

        return $this->redirectToPostedUrl(null, 'trackr/shipments');
    }
}
