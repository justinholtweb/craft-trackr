<?php

namespace justinholtweb\trackr\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use justinholtweb\trackr\Plugin;

/**
 * Trackr settings.
 *
 * Nothing here is ever marked `required`. Craft validates plugin settings wholesale, and a
 * required field would stop a fresh install saving the screen before the merchant has anything
 * to put in it.
 */
class Settings extends Model
{
    // Fulfilment
    // -------------------------------------------------------------------------

    /**
     * Commerce order status handle an order moves to once everything has shipped.
     */
    public string $shippedStatusHandle = '';

    /**
     * Status for an order that is only partly shipped (Pro).
     */
    public string $partiallyShippedStatusHandle = '';

    /**
     * Status for an order whose shipments have all been delivered (Pro).
     */
    public string $deliveredStatusHandle = '';

    /**
     * Move the order to a status at all when tracking is added.
     */
    public bool $updateStatusOnTracking = true;

    /**
     * Count units per shipment and hold the order at "partially shipped" until they are all
     * covered (Pro). Off treats any shipment as shipping the whole order.
     */
    public bool $partialShipmentsEnabled = true;

    /**
     * Leave an order-history note recording the tracking number.
     */
    public bool $addOrderHistoryNote = true;

    /**
     * Provider pre-selected on the order screen.
     */
    public string $defaultProvider = '';

    /**
     * Status a new shipment starts in.
     */
    public string $defaultShipmentStatus = Shipment::STATUS_IN_TRANSIT;

    /**
     * Work the carrier out from the shape of the tracking number when none was given (Pro).
     */
    public bool $autoDetectProvider = true;

    // Providers
    // -------------------------------------------------------------------------

    /**
     * Handles of the providers offered in the picker. Empty offers all of them.
     *
     * @var string[]
     */
    public array $enabledProviders = [];

    /**
     * Merchant-defined providers (Pro), keyed by handle:
     * `['name' => …, 'url' => …, 'logoUrl' => …, 'match' => [...]]`.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $customProviders = [];

    /**
     * Overrides applied to built-in providers, keyed by handle. Only the keys present are
     * changed, so a corrected URL in a future release still reaches merchants who only renamed
     * the carrier.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $providerOverrides = [];

    /**
     * ISO country code whose carriers sort to the top of the picker.
     */
    public string $preferredCountry = '';

    // Tracking widget
    // -------------------------------------------------------------------------

    public string $widgetTitle = 'Track your order';

    /**
     * `list`, `table` or `card`.
     */
    public string $widgetLayout = 'card';

    public string $widgetAccentColor = '#0B6E99';
    public string $widgetTextColor = '#1F2933';
    public string $widgetMutedColor = '#6B7A89';
    public string $widgetBackgroundColor = '#F7F9FA';
    public string $widgetBorderColor = '#DDE3E8';
    public string $widgetButtonTextColor = '#FFFFFF';
    public string $widgetRadius = '6px';

    public bool $widgetShowStatus = true;
    public bool $widgetShowShipDate = true;
    public bool $widgetShowService = true;
    public bool $widgetShowProviderLogo = true;
    public bool $widgetShowTrackButton = true;
    public string $widgetButtonLabel = 'Track shipment';
    public string $widgetFooterText = '';

    /**
     * Site template rendered instead of Trackr's own widget markup. Blank uses the built-in one.
     */
    public string $widgetTemplate = '';

    // Tracking page
    // -------------------------------------------------------------------------

    public bool $trackingPageEnabled = true;

    /**
     * URI the customer-facing tracking page is served at.
     */
    public string $trackingPageUri = 'track';

    /**
     * Site template rendered for the tracking page. Blank uses Trackr's own.
     */
    public string $trackingPageTemplate = '';

    /**
     * Require the email address on the order as well as the order number. Turning this off makes
     * every order's fulfilment readable by anyone who can guess an order number.
     */
    public bool $trackingRequireEmail = true;

    /**
     * Failed lookups allowed from one IP before it is turned away, and the window in seconds.
     */
    public int $trackingMaxAttempts = 10;
    public int $trackingAttemptWindow = 600;

    // Notifications
    // -------------------------------------------------------------------------

    /**
     * Email the customer when a shipment is recorded (Pro). Off leaves notification to whatever
     * Commerce already sends on the status change.
     */
    public bool $notifyOnShipment = false;

    /**
     * Email the customer when a shipment is marked delivered (Pro).
     */
    public bool $notifyOnDelivery = false;

    public string $shipmentEmailSubject = 'Your order {orderNumber} has shipped';
    public string $deliveryEmailSubject = 'Your order {orderNumber} has been delivered';

    /**
     * Site templates for the two emails. Blank uses Trackr's own.
     */
    public string $shipmentEmailTemplate = '';
    public string $deliveryEmailTemplate = '';

    // Import
    // -------------------------------------------------------------------------

    /**
     * Which order identifier a CSV's order-number column holds.
     * One of `auto`, `reference`, `number`, `shortNumber`, `id`.
     */
    public string $csvOrderNumberSource = 'auto';

    /**
     * Extra header names accepted for each import column, on top of the built-in aliases.
     *
     * @var array<string, string[]>
     */
    public array $csvColumnAliases = [];

    /**
     * Directory polled by `trackr/import/watch` for CSV files (Pro). Env-parseable.
     */
    public string $importWatchPath = '';

    /**
     * Move imported files into a `processed/` subdirectory rather than deleting them.
     */
    public bool $importArchiveProcessed = true;

    // Push API (Pro)
    // -------------------------------------------------------------------------

    public bool $apiEnabled = false;

    /**
     * Bearer token third-party fulfilment services authenticate with. Env-parseable.
     */
    public string $apiToken = '';

    // Log
    // -------------------------------------------------------------------------

    public bool $loggingEnabled = true;

    /**
     * Keep request bodies on log rows (Pro). Off keeps only the summary.
     */
    public bool $logPayloads = true;

    /**
     * Days of history to keep. 0 keeps everything.
     */
    public int $logRetentionDays = 30;

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['trackingMaxAttempts'], 'integer', 'min' => 1, 'max' => 1000],
            [['trackingAttemptWindow'], 'integer', 'min' => 30, 'max' => 86400],
            [['logRetentionDays'], 'integer', 'min' => 0],
            [['defaultShipmentStatus'], 'in', 'range' => array_keys(Shipment::statuses())],
            [['widgetLayout'], 'in', 'range' => ['list', 'table', 'card']],
            [['csvOrderNumberSource'], 'in', 'range' => ['auto', 'reference', 'number', 'shortNumber', 'id']],
            [['trackingPageUri'], 'match', 'pattern' => '/^[a-z0-9\-_\/]*$/i', 'message' => Craft::t('trackr', 'Use letters, numbers, slashes and dashes.')],
            [
                [
                    'enabledProviders', 'customProviders', 'providerOverrides', 'csvColumnAliases',
                    'widgetFooterText', 'shipmentEmailSubject', 'deliveryEmailSubject',
                ],
                'safe',
            ],
            [['apiToken', 'importWatchPath'], 'string'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'shippedStatusHandle' => Craft::t('trackr', 'Shipped order status'),
            'partiallyShippedStatusHandle' => Craft::t('trackr', 'Partially shipped order status'),
            'deliveredStatusHandle' => Craft::t('trackr', 'Delivered order status'),
            'trackingPageUri' => Craft::t('trackr', 'Tracking page URI'),
            'apiToken' => Craft::t('trackr', 'API token'),
        ];
    }

    public function getParsedApiToken(): string
    {
        return trim((string)App::parseEnv($this->apiToken));
    }

    /**
     * Whether the push API can authenticate anybody at all. An endpoint with no token has to
     * reject everything rather than let the world write tracking onto orders.
     */
    public function hasApiToken(): bool
    {
        return $this->getParsedApiToken() !== '';
    }

    public function getParsedWatchPath(): string
    {
        return trim((string)App::parseEnv($this->importWatchPath));
    }

    /**
     * The URL a fulfilment service posts tracking to.
     */
    public function getApiEndpointUrl(): string
    {
        return UrlHelper::actionUrl('trackr/api/shipments');
    }

    /**
     * The customer-facing tracking page, optionally deep-linked to one order.
     */
    public function getTrackingPageUrl(?string $orderToken = null): string
    {
        $uri = trim($this->trackingPageUri, '/');

        if ($uri === '') {
            $uri = 'track';
        }

        $url = UrlHelper::siteUrl($uri);

        return $orderToken !== null ? UrlHelper::urlWithParams($url, ['t' => $orderToken]) : $url;
    }

    /**
     * Lite keeps a short tail so the log cannot grow without bound on installs that have no log
     * screen to prune it from.
     */
    public function getEffectiveLogRetentionDays(): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin !== null && !$plugin->isPro()) {
            return 7;
        }

        return $this->logRetentionDays;
    }

    /**
     * Inline CSS custom properties for the widget, so the same values style the CP preview, the
     * emailed widget and the tracking page.
     *
     * @return array<string, string>
     */
    public function getWidgetStyles(): array
    {
        return [
            'accent' => $this->widgetAccentColor,
            'text' => $this->widgetTextColor,
            'muted' => $this->widgetMutedColor,
            'background' => $this->widgetBackgroundColor,
            'border' => $this->widgetBorderColor,
            'buttonText' => $this->widgetButtonTextColor,
            'radius' => $this->widgetRadius,
        ];
    }
}
