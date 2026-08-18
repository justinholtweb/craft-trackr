<?php

namespace justinholtweb\trackr\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\helpers\StringHelper;
use justinholtweb\trackr\Plugin;

/**
 * The customer-facing lookup behind the tracking page.
 *
 * Order fulfilment is customer data. An order number on its own is a guessable, often sequential
 * identifier, so a lookup has to prove something the customer knows — their email address — and
 * a run of failures from one address has to stop being answered at all.
 */
class Tracking extends Component
{
    private const THROTTLE_PREFIX = 'trackr:lookup:';

    /**
     * Find an order from what a customer typed.
     *
     * @return array{order: Order|null, error: string|null, throttled: bool}
     */
    public function lookup(?string $orderNumber, ?string $email, ?string $clientId = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $orderNumber = trim((string)$orderNumber);
        $email = trim((string)$email);

        if ($this->isThrottled($clientId)) {
            return [
                'order' => null,
                'error' => Craft::t('trackr', 'Too many attempts. Try again later.'),
                'throttled' => true,
            ];
        }

        if ($orderNumber === '') {
            return ['order' => null, 'error' => Craft::t('trackr', 'Enter your order number.'), 'throttled' => false];
        }

        if ($settings->trackingRequireEmail && $email === '') {
            return ['order' => null, 'error' => Craft::t('trackr', 'Enter the email address on the order.'), 'throttled' => false];
        }

        $order = $this->findOrder($orderNumber);

        // Every failure answers the same way. Telling a stranger that an order number exists but
        // the email is wrong is telling them the order number exists.
        if ($order === null || !$this->emailMatches($order, $email, $settings->trackingRequireEmail)) {
            $this->recordFailure($clientId);

            return [
                'order' => null,
                'error' => Craft::t('trackr', 'We couldn’t find an order with those details.'),
                'throttled' => false,
            ];
        }

        return ['order' => $order, 'error' => null, 'throttled' => false];
    }

    /**
     * Find an order from the token in an emailed tracking link — the order's UID, which is
     * random and so needs no second factor.
     */
    public function lookupByToken(?string $token): ?Order
    {
        $token = trim((string)$token);

        if ($token === '' || !StringHelper::isUUID($token)) {
            return null;
        }

        $order = Order::find()->uid($token)->status(null)->one();

        return $order instanceof Order && $order->isCompleted ? $order : null;
    }

    /**
     * Match the number against every identifier an order has, because customers copy whichever
     * one the store showed them.
     */
    public function findOrder(string $orderNumber): ?Order
    {
        $orderNumber = trim($orderNumber);

        // Craft's query params treat `*`, `,` and `:` as syntax. A customer typing `*` must not
        // be handed "any order at all".
        if ($orderNumber === '' || preg_match('/[*,:]/', $orderNumber)) {
            return null;
        }

        $order = Order::find()->reference($orderNumber)->isCompleted(true)->status(null)->one();

        if ($order instanceof Order) {
            return $order;
        }

        if (preg_match('/^[0-9a-f]{7,32}$/i', $orderNumber)) {
            if (strlen($orderNumber) === 32) {
                $order = Order::find()->number(strtolower($orderNumber))->isCompleted(true)->status(null)->one();

                if ($order instanceof Order) {
                    return $order;
                }
            }

            // `shortNumber` is the first seven characters of `number`, and is what Commerce
            // shows on the front end by default.
            $order = Order::find()
                ->shortNumber(strtolower(substr($orderNumber, 0, 7)))
                ->isCompleted(true)
                ->status(null)
                ->one();

            if ($order instanceof Order) {
                return $order;
            }
        }

        if (ctype_digit($orderNumber)) {
            $order = Order::find()->id((int)$orderNumber)->isCompleted(true)->status(null)->one();

            if ($order instanceof Order) {
                return $order;
            }
        }

        return null;
    }

    /**
     * @return array{shipments: array, progress: array, state: \justinholtweb\trackr\models\OrderState}
     */
    public function summarize(Order $order): array
    {
        $shipments = Plugin::getInstance()->getShipments();

        return [
            'shipments' => $shipments->getShipmentsForOrder((int)$order->id),
            'progress' => $shipments->getProgress($order),
            'state' => $shipments->getOrderState((int)$order->id),
        ];
    }

    public function isThrottled(?string $clientId): bool
    {
        if ($clientId === null || $clientId === '') {
            return false;
        }

        $settings = Plugin::getInstance()->getSettings();
        $attempts = (int)Craft::$app->getCache()->get(self::THROTTLE_PREFIX . md5($clientId));

        return $attempts >= $settings->trackingMaxAttempts;
    }

    public function recordFailure(?string $clientId): void
    {
        if ($clientId === null || $clientId === '') {
            return;
        }

        $settings = Plugin::getInstance()->getSettings();
        $cache = Craft::$app->getCache();
        $key = self::THROTTLE_PREFIX . md5($clientId);

        $attempts = (int)$cache->get($key) + 1;

        // A sliding window would let a patient attacker through forever; the window is reset on
        // each failure so a run of them keeps the door shut.
        $cache->set($key, $attempts, $settings->trackingAttemptWindow);
    }

    public function clearThrottle(?string $clientId): void
    {
        if ($clientId !== null && $clientId !== '') {
            Craft::$app->getCache()->delete(self::THROTTLE_PREFIX . md5($clientId));
        }
    }

    private function emailMatches(Order $order, string $email, bool $required): bool
    {
        if (!$required) {
            return true;
        }

        $orderEmail = strtolower(trim((string)$order->getEmail()));
        $given = strtolower(trim($email));

        return $orderEmail !== '' && hash_equals($orderEmail, $given);
    }
}
