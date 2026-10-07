<?php

namespace justinholtweb\trackr\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\trackr\helpers\RateLimit;
use justinholtweb\trackr\models\Shipment;
use justinholtweb\trackr\Plugin;
use justinholtweb\trackr\twig\TrackrVariable;
use Throwable;
use yii\web\Response;

/**
 * The push API (Pro).
 *
 * Fulfilment services, 3PLs, ERPs and label printers all want to hand tracking back the moment a
 * label is bought. This is the endpoint they post to. It writes through
 * `Shipments::record()` like everything else, so a service that retries — and they all retry —
 * updates one shipment instead of stacking up duplicates.
 *
 * Authentication is a bearer token. Apache commonly strips the `Authorization` header, so an
 * `X-Trackr-Token` header and a `trackr_token` query parameter are accepted as well.
 */
class ApiController extends Controller
{
    /** Failed sign-ins a client may make in a minute before it is shut out. */
    private const AUTH_FAILURES_PER_MINUTE = 10;

    /** How long a client that ran out of failed sign-ins is shut out for. */
    private const AUTH_BLOCK_SECONDS = 120;

    private const AUTH_BLOCK_PREFIX = 'trackr:api-blocked:';
    private const AUTH_LOGGED_PREFIX = 'trackr:api-auth-logged:';

    /**
     * @inheritdoc
     */
    public array|bool|int $allowAnonymous = true;

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    /**
     * @inheritdoc
     */
    public $defaultAction = 'shipments';

    /**
     * `POST /actions/trackr/api/shipments` — record tracking.
     * `GET  /actions/trackr/api/shipments?order_number=…` — read it back.
     */
    public function actionShipments(): Response
    {
        $denied = $this->authenticate();

        if ($denied !== null) {
            return $denied;
        }

        return $this->request->getIsPost() ? $this->createShipment() : $this->readShipments();
    }

    /**
     * `POST /actions/trackr/api/status` — move a shipment along its delivery status.
     */
    public function actionStatus(): Response
    {
        $denied = $this->authenticate();

        if ($denied !== null) {
            return $denied;
        }

        $this->requirePostRequest();

        $body = $this->body();
        $trackingNumber = trim((string)($body['tracking_number'] ?? $body['trackingNumber'] ?? ''));
        $status = strtolower(str_replace([' ', '-'], '_', (string)($body['status'] ?? '')));

        if ($trackingNumber === '') {
            return $this->error(400, 'A tracking_number is required.');
        }

        if (!isset(Shipment::statuses()[$status])) {
            return $this->error(400, 'Unknown status. Use one of: ' . implode(', ', array_keys(Shipment::statuses())) . '.');
        }

        $plugin = Plugin::getInstance();
        $shipments = $plugin->getShipments()->getShipments(['search' => $trackingNumber], 25);

        $match = null;

        foreach ($shipments as $shipment) {
            if (strcasecmp((string)$shipment->trackingNumber, $trackingNumber) === 0) {
                $match = $shipment;
                break;
            }
        }

        if ($match === null) {
            return $this->error(404, 'No shipment with that tracking number.');
        }

        $updated = $plugin->getShipments()->updateStatus($match, $status, $body['status_detail'] ?? null);

        $plugin->getLog()->write('api.status', sprintf('%s → %s', $trackingNumber, $status), [
            'orderId' => $match->orderId,
            'source' => Shipment::SOURCE_API,
            'level' => $updated ? 'info' : 'warning',
        ]);

        if (!$updated) {
            return $this->error(500, 'Could not update that shipment.');
        }

        return $this->asJson([
            'success' => true,
            'shipment' => $plugin->getShipments()->getShipmentById((int)$match->id)?->toWidgetArray(),
        ]);
    }

    /**
     * `GET /actions/trackr/api/carriers` — the carrier list, so a client can send handles Trackr
     * already understands instead of guessing at names.
     */
    public function actionCarriers(): Response
    {
        $denied = $this->authenticate();

        if ($denied !== null) {
            return $denied;
        }

        $carriers = [];

        foreach (Plugin::getInstance()->getProviders()->getEnabledProviders() as $handle => $provider) {
            $carriers[] = [
                'handle' => $handle,
                'name' => $provider->name,
                'country' => $provider->country,
                'hasTrackingUrl' => $provider->hasUrl(),
            ];
        }

        return $this->asJson(['success' => true, 'carriers' => $carriers]);
    }

    private function createShipment(): Response
    {
        $plugin = Plugin::getInstance();
        $body = $this->body();

        $orderNumber = trim((string)($body['order_number'] ?? $body['orderNumber'] ?? ''));
        $orderId = (int)($body['order_id'] ?? $body['orderId'] ?? 0);

        $order = $orderId > 0
            ? $plugin->getShipments()->getOrderById($orderId)
            : ($orderNumber !== '' ? $plugin->getTracking()->findOrder($orderNumber) : null);

        if ($order === null) {
            $plugin->getLog()->write('api.reject', 'Unknown order ' . ($orderNumber ?: $orderId), [
                'level' => 'warning',
                'source' => Shipment::SOURCE_API,
            ]);

            return $this->error(404, 'No order matches that order_number.');
        }

        $result = $plugin->getShipments()->record($order, [
            'provider' => $body['carrier'] ?? $body['provider'] ?? $body['carrier_code'] ?? null,
            'trackingNumber' => $body['tracking_number'] ?? $body['trackingNumber'] ?? null,
            'trackingUrl' => $body['tracking_url'] ?? $body['trackingUrl'] ?? null,
            'service' => $body['service'] ?? $body['shipping_service'] ?? null,
            'shipDate' => $body['ship_date'] ?? $body['shipDate'] ?? null,
            'status' => isset($body['status'])
                ? strtolower(str_replace([' ', '-'], '_', (string)$body['status']))
                : null,
            'statusDetail' => $body['status_detail'] ?? null,
            'note' => $body['note'] ?? null,
            'items' => $body['items'] ?? $body['line_items'] ?? [],
            'partial' => (bool)($body['partial'] ?? false),
            'source' => Shipment::SOURCE_API,
            'notify' => array_key_exists('notify', $body) ? (bool)$body['notify'] : null,
        ]);

        if ($result['shipment'] === null) {
            return $this->error(422, 'Could not record that shipment.', ['errors' => $result['errors']]);
        }

        $this->response->setStatusCode($result['isNew'] ? 201 : 200);

        return $this->asJson([
            'success' => true,
            'created' => $result['isNew'],
            'orderId' => (int)$order->id,
            'orderNumber' => TrackrVariable::numberFor($order),
            'fullyShipped' => $result['fullyShipped'],
            'shipment' => $result['shipment']->toWidgetArray(),
        ]);
    }

    private function readShipments(): Response
    {
        $plugin = Plugin::getInstance();
        $request = $this->request;

        $orderNumber = trim((string)$request->getQueryParam('order_number', ''));
        $orderId = (int)$request->getQueryParam('order_id', 0);

        $order = $orderId > 0
            ? $plugin->getShipments()->getOrderById($orderId)
            : ($orderNumber !== '' ? $plugin->getTracking()->findOrder($orderNumber) : null);

        if ($order === null) {
            return $this->error(404, 'No order matches that order_number.');
        }

        $shipments = $plugin->getShipments()->getShipmentsForOrder((int)$order->id);

        return $this->asJson([
            'success' => true,
            'orderId' => (int)$order->id,
            'orderNumber' => TrackrVariable::numberFor($order),
            'progress' => $plugin->getShipments()->getProgress($order),
            'shipments' => array_map(static fn(Shipment $shipment) => $shipment->toWidgetArray(), $shipments),
        ]);
    }

    /**
     * @return Response|null a response when the request is rejected, null when it may proceed
     */
    private function authenticate(): ?Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$plugin->isPro()) {
            return $this->error(403, 'The tracking API is a Pro feature.');
        }

        if (!$settings->apiEnabled) {
            return $this->error(403, 'The tracking API is switched off.');
        }

        // An endpoint with no token has to reject everything. Anything else would let the world
        // write tracking numbers onto other people's orders.
        if (!$settings->hasApiToken()) {
            return $this->error(403, 'No API token is configured.');
        }

        // A client that keeps getting the token wrong is shut out for a while before its next
        // guess is even compared. The token is long enough that guessing is hopeless; the point is
        // that each rejection used to cost a log row, so a loop of bad requests was a way to fill
        // the database.
        $client = RateLimit::client();
        $blockedKey = self::AUTH_BLOCK_PREFIX . sha1($client);

        if (Craft::$app->getCache()->get($blockedKey)) {
            return $this->error(429, 'Too many failed attempts. Try again later.');
        }

        $presented = $this->presentedToken();

        if ($presented === '' || !hash_equals($settings->getParsedApiToken(), $presented)) {
            if (!RateLimit::allow('api-auth-fail', self::AUTH_FAILURES_PER_MINUTE)) {
                Craft::$app->getCache()->set($blockedKey, true, self::AUTH_BLOCK_SECONDS);
            }

            // One row per client per minute, however many attempts it made in it.
            if (Craft::$app->getCache()->add(self::AUTH_LOGGED_PREFIX . sha1($client) . ':' . intdiv(time(), 60), true, 120)) {
                $plugin->getLog()->write('api.auth', 'Rejected an unauthenticated API request', [
                    'level' => 'warning',
                    'source' => Shipment::SOURCE_API,
                ]);
            }

            return $this->error(401, 'Bad or missing token.');
        }

        return null;
    }

    private function presentedToken(): string
    {
        $request = $this->request;

        $header = (string)$request->getHeaders()->get('Authorization', '');

        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }

        $custom = (string)$request->getHeaders()->get('X-Trackr-Token', '');

        if ($custom !== '') {
            return trim($custom);
        }

        // Deliberately not `token`: that is Craft's own preview-token parameter, and Craft
        // rejects the whole request with "Invalid token" long before a controller sees it.
        return trim((string)$request->getParam('trackr_token', ''));
    }

    /**
     * Body parameters, whether the client sent JSON or a form post.
     *
     * @return array<string, mixed>
     */
    private function body(): array
    {
        $params = $this->request->getBodyParams();

        if ($params !== []) {
            return $params;
        }

        try {
            $raw = $this->request->getRawBody();

            if (trim($raw) === '') {
                return [];
            }

            $decoded = Json::decodeIfJson($raw);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function error(int $statusCode, string $message, array $extra = []): Response
    {
        $this->response->setStatusCode($statusCode);

        return $this->asJson(array_merge(['success' => false, 'message' => $message], $extra));
    }
}
