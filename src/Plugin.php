<?php

namespace justinholtweb\trackr;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\trackr\models\Settings;
use justinholtweb\trackr\services\Imports;
use justinholtweb\trackr\services\Log;
use justinholtweb\trackr\services\Notifications;
use justinholtweb\trackr\services\Providers;
use justinholtweb\trackr\services\Shipments;
use justinholtweb\trackr\services\Statuses;
use justinholtweb\trackr\services\Tracking;
use justinholtweb\trackr\services\Widget;
use justinholtweb\trackr\twig\TrackrVariable;
use yii\base\Event;

/**
 * Trackr — shipment tracking for Craft Commerce.
 *
 * @property-read Shipments $shipments
 * @property-read Providers $providers
 * @property-read Statuses $statuses
 * @property-read Widget $widget
 * @property-read Imports $imports
 * @property-read Tracking $tracking
 * @property-read Notifications $notifications
 * @property-read Log $log
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'trackr';

    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '5.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'shipments' => ['class' => Shipments::class],
                'providers' => ['class' => Providers::class],
                'statuses' => ['class' => Statuses::class],
                'widget' => ['class' => Widget::class],
                'imports' => ['class' => Imports::class],
                'tracking' => ['class' => Tracking::class],
                'notifications' => ['class' => Notifications::class],
                'log' => ['class' => Log::class],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerCpRoutes();
        $this->_registerSiteRoutes();

        // Trackr can be installed while Commerce is disabled or mid-upgrade. Everything below
        // reaches for an order, so it all has to wait for Commerce to actually be there.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerOrderEditPanel();
    }

    /**
     * Whether Commerce is present and enabled.
     */
    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    /**
     * Whether this install is licensed for the Pro feature set.
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getShipments(): Shipments
    {
        return $this->get('shipments');
    }

    public function getProviders(): Providers
    {
        return $this->get('providers');
    }

    public function getStatuses(): Statuses
    {
        return $this->get('statuses');
    }

    public function getWidget(): Widget
    {
        return $this->get('widget');
    }

    public function getImports(): Imports
    {
        return $this->get('imports');
    }

    public function getTracking(): Tracking
    {
        return $this->get('tracking');
    }

    public function getNotifications(): Notifications
    {
        return $this->get('notifications');
    }

    public function getLog(): Log
    {
        return $this->get('log');
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('trackr/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('trackr', 'Trackr');

        $user = Craft::$app->getUser();
        $subNav = [];

        if ($user->checkPermission('trackr-viewShipments')) {
            $subNav['shipments'] = [
                'label' => Craft::t('trackr', 'Shipments'),
                'url' => 'trackr/shipments',
            ];
        }

        if ($user->checkPermission('trackr-manageShipments')) {
            $subNav['import'] = [
                'label' => Craft::t('trackr', 'Import'),
                'url' => 'trackr/import',
            ];
        }

        if ($user->checkPermission('trackr-manageProviders')) {
            $subNav['providers'] = [
                'label' => Craft::t('trackr', 'Carriers'),
                'url' => 'trackr/providers',
            ];
        }

        if ($this->isPro() && $user->checkPermission('trackr-viewLog')) {
            $subNav['log'] = [
                'label' => Craft::t('trackr', 'Log'),
                'url' => 'trackr/log',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = [
                'label' => Craft::t('trackr', 'Settings'),
                'url' => 'settings/plugins/trackr',
            ];
        }

        if (!$subNav) {
            return null;
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('trackr', TrackrVariable::class);
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('trackr', 'Trackr'),
                    'permissions' => [
                        'trackr-viewShipments' => [
                            'label' => Craft::t('trackr', 'View shipments'),
                            'nested' => [
                                'trackr-manageShipments' => [
                                    'label' => Craft::t('trackr', 'Add, edit and delete tracking'),
                                ],
                            ],
                        ],
                        'trackr-manageProviders' => [
                            'label' => Craft::t('trackr', 'Manage carriers'),
                        ],
                        'trackr-viewLog' => [
                            'label' => Craft::t('trackr', 'View the activity log'),
                            'nested' => [
                                'trackr-manageLog' => [
                                    'label' => Craft::t('trackr', 'Prune and clear the activity log'),
                                ],
                            ],
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['trackr'] = 'trackr/shipments/index';
                $event->rules['trackr/shipments'] = 'trackr/shipments/index';
                $event->rules['trackr/shipments/<shipmentId:\d+>'] = 'trackr/shipments/detail';
                $event->rules['trackr/import'] = 'trackr/import/index';
                $event->rules['trackr/providers'] = 'trackr/providers/index';
                $event->rules['trackr/providers/<handle:[\w\-]+>'] = 'trackr/providers/edit';
                $event->rules['trackr/log'] = 'trackr/log/index';
                $event->rules['trackr/log/<entryId:\d+>'] = 'trackr/log/detail';
            }
        );
    }

    /**
     * The customer-facing tracking page lives at whatever URI the merchant configured, so the
     * rule is registered from settings rather than hard-coded.
     */
    private function _registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $settings = $this->getSettings();

                if (!$settings->trackingPageEnabled) {
                    return;
                }

                $uri = trim($settings->trackingPageUri, '/');

                if ($uri === '') {
                    return;
                }

                $event->rules[$uri] = 'trackr/track/index';
            }
        );
    }

    /**
     * Tracking is added and read on Commerce's own order edit screen — the place staff are
     * already standing when a parcel goes out.
     */
    private function _registerOrderEditPanel(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context) {
            $order = $context['order'] ?? null;

            if (!$order instanceof Order || !$order->id) {
                return null;
            }

            if (!Craft::$app->getUser()->checkPermission('trackr-viewShipments')) {
                return null;
            }

            return Craft::$app->getView()->renderTemplate('trackr/_order-panel', [
                'order' => $order,
                'shipments' => $this->getShipments()->getShipmentsForOrder((int)$order->id),
                'state' => $this->getShipments()->getOrderState((int)$order->id),
                'progress' => $this->getShipments()->getProgress($order),
                'providerOptions' => $this->getProviders()->getSelectOptions(),
                'statusOptions' => $this->shipmentStatusOptions(),
                'unshipped' => $this->isPro() ? $this->getShipments()->getUnshippedLineItemQtys($order) : [],
                'isPro' => $this->isPro(),
                'settings' => $this->getSettings(),
                'canManage' => Craft::$app->getUser()->checkPermission('trackr-manageShipments'),
            ], View::TEMPLATE_MODE_CP);
        });
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function shipmentStatusOptions(): array
    {
        $options = [];

        foreach (models\Shipment::statuses() as $value => $label) {
            $options[] = ['label' => Craft::t('trackr', $label), 'value' => $value];
        }

        return $options;
    }
}
