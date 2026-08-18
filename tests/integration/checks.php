<?php
/**
 * Trackr integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-trackr/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: fixture products, orders, shipments, log rows and the plugin
 * settings it overwrites are all restored in a `finally`, pass or fail.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use justinholtweb\trackr\db\Table;
use justinholtweb\trackr\models\OrderState;
use justinholtweb\trackr\models\Provider;
use justinholtweb\trackr\models\Settings;
use justinholtweb\trackr\models\Shipment;
use justinholtweb\trackr\Plugin;
use justinholtweb\trackr\twig\TrackrVariable;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$storeId = $commerce->getStores()->getPrimaryStore()->id;
$suffix = substr(md5((string)microtime(true)), 0, 6);

$createdProducts = [];
$createdOrders = [];
$tempFiles = [];
$originalSettings = $plugin->getSettings()->toArray();
$originalEdition = Craft::$app->getPlugins()->getPluginInfo(Plugin::HANDLE)['edition'] ?? Plugin::EDITION_LITE;

/**
 * Editions are project config, so switching one has to be flushed like any other config write.
 */
function switchEdition(string $edition): void
{
    try {
        Craft::$app->getPlugins()->switchEdition(Plugin::HANDLE, $edition);
    } catch (craft\errors\StaleResourceException) {
        Craft::$app->getProjectConfig()->reset();
        Craft::$app->getPlugins()->switchEdition(Plugin::HANDLE, $edition);
    }

    Craft::$app->getProjectConfig()->saveModifiedConfigData();
}

// `craft-penny` (a sibling plugin in this shared harness) registers an
// Elements::EVENT_BEFORE_SAVE_ELEMENT handler typed `ModelEvent`, but Craft passes an
// `ElementEvent` for that event — so saving *any* element fatals while it is enabled. Nothing to
// do with Trackr; detached in-process here (never persisted) so fixtures can be created.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
}

$appliedSettings = [];

/**
 * Project config writes are buffered until the request ends, and a bare console script has no
 * request end — so it has to flush them itself.
 *
 * Every call hands Craft the *whole* settings array: `savePluginSettings()` replaces the plugin's
 * settings node in project config with only the keys it is given, so a partial save would reset
 * every other setting to its default.
 */
function applySettings(array $values): void
{
    global $plugin, $appliedSettings;

    $appliedSettings = array_merge($appliedSettings, $values);
    $merged = array_merge($plugin->getSettings()->toArray(), $appliedSettings);

    Craft::$app->getPlugins()->savePluginSettings($plugin, $merged);

    try {
        Craft::$app->getProjectConfig()->saveModifiedConfigData();
    } catch (craft\errors\StaleResourceException) {
        // The live HTTP checks run their own Craft processes, which can move project config on
        // under this one. Re-read it and write again rather than failing the check that asked.
        Craft::$app->getProjectConfig()->reset();
        Craft::$app->getPlugins()->savePluginSettings($plugin, $merged);
        Craft::$app->getProjectConfig()->saveModifiedConfigData();
    }

    $plugin->getProviders()->clearCaches();
}

function makeProduct(string $sku, float $price = 20.0): Product
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Trackr fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->weight = 1;
    $variant->isDefault = true;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product;
}

/**
 * @param array<int, array{variant: Variant, qty: int}> $lines
 */
function makeOrder(array $lines, bool $complete = true, string $email = 'buyer@example.com'): Order
{
    global $createdOrders, $storeId;

    $commerce = Commerce::getInstance();

    $order = new Order();
    $order->storeId = $storeId;
    $order->setEmail($email);
    $order->orderLanguage = Craft::$app->language;
    $order->currency = $commerce->getStores()->getPrimaryStore()->getCurrency();

    if (!Craft::$app->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save fixture order: ' . json_encode($order->getErrors()));
    }

    foreach ($lines as $line) {
        $lineItem = $commerce->getLineItems()->create($order, [
            'purchasableId' => $line['variant']->id,
            'qty' => $line['qty'],
        ]);
        $order->addLineItem($lineItem);
    }

    if (!Craft::$app->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not add fixture line items: ' . json_encode($order->getErrors()));
    }

    if ($complete) {
        $order->markAsComplete();
    }

    $createdOrders[] = $order;

    return Order::find()->id($order->id)->status(null)->one();
}

function writeCsv(string $contents): string
{
    global $tempFiles;

    $path = sys_get_temp_dir() . '/trackr-check-' . bin2hex(random_bytes(6)) . '.csv';
    file_put_contents($path, $contents);
    $tempFiles[] = $path;

    return $path;
}

echo "\nTrackr integration checks\n";
echo str_repeat('=', 60) . "\n";

try {
    // ---------------------------------------------------------------------
    section('Plugin');

    check('the plugin is installed and instantiable', fn() => $plugin instanceof Plugin);
    check('Commerce is ready', fn() => Plugin::commerceIsReady() === true);
    check('every service resolves', function() use ($plugin) {
        foreach (['shipments', 'providers', 'statuses', 'widget', 'imports', 'tracking', 'notifications', 'log'] as $handle) {
            if ($plugin->get($handle) === null) {
                return "missing $handle";
            }
        }

        return true;
    });
    check('both editions are declared', fn() => Plugin::editions() === ['lite', 'pro']);

    switchEdition(Plugin::EDITION_PRO);
    applySettings([
        'shippedStatusHandle' => '',
        'partiallyShippedStatusHandle' => '',
        'deliveredStatusHandle' => '',
        'updateStatusOnTracking' => true,
        'partialShipmentsEnabled' => true,
        'autoDetectProvider' => true,
        'notifyOnShipment' => false,
        'notifyOnDelivery' => false,
        'apiEnabled' => true,
        'apiToken' => 'trackr-test-token-' . $suffix,
        'enabledProviders' => [],
        'customProviders' => [],
        'providerOverrides' => [],
        'trackingPageEnabled' => true,
        'trackingRequireEmail' => true,
        'loggingEnabled' => true,
        'logPayloads' => true,
    ]);

    check('the plugin is on Pro for this run', fn() => $plugin->isPro() === true);

    // ---------------------------------------------------------------------
    section('Providers');

    $providers = $plugin->getProviders();

    check('the built-in registry loads', fn() => count($providers->getBuiltInRegistry()) > 100
        ?: 'got ' . count($providers->getBuiltInRegistry()));

    check('every built-in has a name and a country', function() use ($providers) {
        foreach ($providers->getBuiltInRegistry() as $handle => $config) {
            if (($config['name'] ?? '') === '' || ($config['country'] ?? '') === '') {
                return "incomplete: $handle";
            }
        }

        return true;
    });

    check('every built-in URL template names the tracking number, or has no URL at all', function() use ($providers) {
        foreach ($providers->getBuiltInRegistry() as $handle => $config) {
            $url = (string)($config['url'] ?? '');

            if ($url !== '' && !str_contains($url, '{tracking_number}')) {
                return "no placeholder: $handle";
            }
        }

        return true;
    });

    check('every built-in URL is https', function() use ($providers) {
        foreach ($providers->getBuiltInRegistry() as $handle => $config) {
            $url = (string)($config['url'] ?? '');

            if ($url !== '' && !str_starts_with($url, 'https://')) {
                return "not https: $handle";
            }
        }

        return true;
    });

    check('every detection pattern compiles', function() use ($providers) {
        foreach ($providers->getBuiltInRegistry() as $handle => $config) {
            foreach ((array)($config['match'] ?? []) as $pattern) {
                if (@preg_match($pattern, 'TEST') === false) {
                    return "bad pattern on $handle: $pattern";
                }
            }
        }

        return true;
    });

    check('a provider resolves by handle', fn() => $providers->resolve('ups')?->handle === 'ups');
    check('a provider resolves by display name', fn() => $providers->resolve('UPS')?->handle === 'ups');
    check('a provider resolves through punctuation', fn() => $providers->resolve('DHL-Express')?->handle === 'dhl-express');
    check('a provider resolves case-insensitively', fn() => $providers->resolve('fedex')?->handle === 'fedex');
    check('a service name falls back to its carrier', fn() => $providers->resolve('UPS Ground Saver')?->handle === 'ups');
    check('an unknown carrier resolves to null', fn() => $providers->resolve('Cormorant Post') === null);
    check('an empty carrier resolves to null', fn() => $providers->resolve('') === null);

    check('a tracking URL is built from the template', function() use ($providers) {
        $url = $providers->trackingUrl('ups', '1Z999AA10123456784');

        return $url === 'https://www.ups.com/track?loc=en_US&tracknum=1Z999AA10123456784' ?: 'got ' . var_export($url, true);
    });

    check('a tracking number is URL-encoded into the template', function() use ($providers) {
        $url = $providers->trackingUrl('ups', 'ABC 123/45');

        return str_contains((string)$url, 'ABC%20123%2F45') ?: 'got ' . var_export($url, true);
    });

    check('a carrier with no URL yields no link', function() use ($providers) {
        return $providers->trackingUrl('customer-pickup', '12345') === null;
    });

    check('an unknown carrier yields no link rather than a guess', function() use ($providers) {
        return $providers->trackingUrl('Cormorant Post', '12345') === null;
    });

    check('an empty tracking number yields no link', fn() => $providers->trackingUrl('ups', '') === null);

    check('extra placeholders are filled from context', function() use ($providers) {
        $url = $providers->trackingUrl('postnl', '3SABCD1234567', [
            'postal_code' => '1012AB',
            'country' => 'NL',
        ]);

        return str_contains((string)$url, '1012AB') && str_contains((string)$url, 'D=NL')
            ?: 'got ' . var_export($url, true);
    });

    check('auto-detection recognises a UPS number', fn() => $providers->detect('1Z999AA10123456784')?->handle === 'ups');
    check('auto-detection recognises a USPS number', fn() => $providers->detect('9400111899223197428490')?->handle === 'usps');
    check('auto-detection recognises an Amazon number', fn() => $providers->detect('TBA123456789012')?->handle === 'amazon-shipping');

    check('auto-detection declines to guess when two carriers match', function() use ($providers) {
        // Twelve digits is FedEx's shape and several others'.
        return $providers->detect('123456789012') === null || $providers->detect('123456789012')->handle === 'fedex';
    });

    check('auto-detection on nonsense returns null', fn() => $providers->detect('not-a-tracking-number-really') === null);
    check('auto-detection on an empty string returns null', fn() => $providers->detect('') === null);

    check('the select options start with a blank choice', function() use ($providers) {
        $options = $providers->getSelectOptions();

        return ($options[0]['value'] ?? null) === '' ?: 'got ' . var_export($options[0] ?? null, true);
    });

    check('disabling a carrier removes it from the picker', function() use ($plugin, $providers) {
        $providers->saveOverride('yodel', ['enabled' => false]);
        $providers->clearCaches();

        $enabled = $providers->getEnabledProviders();
        $all = $providers->getAllProviders();

        $providers->saveOverride('yodel', []);
        $providers->clearCaches();

        return !isset($enabled['yodel']) && isset($all['yodel'])
            ?: 'disabled carrier still offered';
    });

    check('an override renames a built-in without copying it', function() use ($providers) {
        $providers->saveOverride('ups', ['name' => 'Our courier']);
        $providers->clearCaches();

        $provider = $providers->getProviderByHandle('ups');
        $renamed = $provider?->name === 'Our courier';
        // The URL still comes from the registry, so a future correction reaches this merchant.
        $keptUrl = str_contains((string)$provider?->url, 'ups.com');

        $providers->saveOverride('ups', []);
        $providers->clearCaches();

        return ($renamed && $keptUrl) ?: 'renamed=' . var_export($renamed, true) . ' keptUrl=' . var_export($keptUrl, true);
    });

    check('an override on an unknown handle is refused', fn() => $providers->saveOverride('not-a-carrier', ['name' => 'x']) === false);

    check('a custom carrier can be added and resolved', function() use ($providers) {
        $providers->saveCustomProvider('van', [
            'name' => 'Our van',
            'url' => 'https://example.test/track/{tracking_number}',
            'match' => ['/^VAN\d{4}$/'],
        ]);
        $providers->clearCaches();

        $byHandle = $providers->getProviderByHandle('van')?->name === 'Our van';
        $byName = $providers->resolve('Our van')?->handle === 'van';
        $url = $providers->trackingUrl('van', 'VAN0001') === 'https://example.test/track/VAN0001';
        $detected = $providers->detect('VAN0001')?->handle === 'van';

        return ($byHandle && $byName && $url && $detected)
            ?: "handle=$byHandle name=$byName url=$url detected=$detected";
    });

    check('a custom carrier can be deleted', function() use ($providers) {
        $deleted = $providers->deleteCustomProvider('van');
        $providers->clearCaches();

        return $deleted && $providers->getProviderByHandle('van') === null;
    });

    check('deleting an unknown custom carrier reports failure', fn() => $providers->deleteCustomProvider('nope') === false);

    check('a malformed custom pattern cannot take the site down', function() use ($providers) {
        $provider = Provider::fromArray('broken', ['name' => 'Broken', 'match' => ['/[unclosed/']]);

        return $provider->matches('ANYTHING') === false;
    });

    check('the preferred country sorts its carriers first', function() use ($plugin, $providers) {
        applySettings(['preferredCountry' => 'GB']);
        $providers->clearCaches();

        $first = array_key_first($providers->getEnabledProviders());
        $country = $providers->getProviderByHandle($first)?->country;

        applySettings(['preferredCountry' => '']);
        $providers->clearCaches();

        return $country === 'GB' ?: "first carrier was from $country";
    });

    // ---------------------------------------------------------------------
    section('Models');

    check('the delivery statuses are the seven expected', function() {
        return array_keys(Shipment::statuses()) === [
            'pending', 'in_transit', 'out_for_delivery', 'delivered', 'exception', 'returned', 'cancelled',
        ];
    });

    check('a shipment reads dates out of raw row strings', function() {
        $shipment = new Shipment(['shipDate' => '2026-08-14 10:30:00']);

        return $shipment->shipDate instanceof DateTime && $shipment->shipDate->format('Y-m-d') === '2026-08-14';
    });

    check('items are normalised from JSON', function() {
        $shipment = new Shipment(['items' => '[{"lineItemId":5,"qty":2}]']);

        return $shipment->getItems() === [['lineItemId' => 5, 'qty' => 2]];
    });

    check('junk items are discarded', function() {
        $shipment = new Shipment(['items' => '[{"lineItemId":0,"qty":2},{"qty":0},"nope"]']);

        return $shipment->getItems() === [];
    });

    check('an item-level shipment reports its own quantity', function() {
        $shipment = new Shipment(['items' => '[{"lineItemId":5,"qty":2},{"lineItemId":6,"qty":3}]', 'shippedQty' => 99]);

        return $shipment->getQty() === 5 ?: 'got ' . $shipment->getQty();
    });

    check('an order-level shipment reports its recorded quantity', function() {
        $shipment = new Shipment(['items' => '[]', 'shippedQty' => 4]);

        return $shipment->getQty() === 4;
    });

    check('a delivered shipment is not active', function() {
        return (new Shipment(['status' => Shipment::STATUS_DELIVERED]))->getIsActive() === false;
    });

    check('an in-transit shipment is active', function() {
        return (new Shipment(['status' => Shipment::STATUS_IN_TRANSIT]))->getIsActive() === true;
    });

    check('the stored carrier name survives the carrier being renamed', function() {
        $shipment = new Shipment(['providerHandle' => 'ups', 'providerName' => 'UPS as it was']);

        return $shipment->getProviderLabel() === 'UPS as it was';
    });

    check('settings default to sane values', function() {
        $settings = new Settings();

        return $settings->trackingPageUri === 'track'
            && $settings->defaultShipmentStatus === Shipment::STATUS_IN_TRANSIT
            && $settings->trackingRequireEmail === true;
    });

    check('settings reject an unknown default status', function() {
        $settings = new Settings(['defaultShipmentStatus' => 'teleported']);

        return $settings->validate(['defaultShipmentStatus']) === false;
    });

    check('settings reject a tracking URI with a space in it', function() {
        $settings = new Settings(['trackingPageUri' => 'my page']);

        return $settings->validate(['trackingPageUri']) === false;
    });

    check('an unset API token means the endpoint can authenticate nobody', function() {
        return (new Settings())->hasApiToken() === false;
    });

    check('the tracking page URL carries the order token', function() use ($plugin) {
        $url = $plugin->getSettings()->getTrackingPageUrl('abc-123');

        return str_contains($url, 't=abc-123') ?: 'got ' . $url;
    });

    // ---------------------------------------------------------------------
    section('Recording shipments');

    $variantA = makeProduct("TRACKR-A-$suffix")->getDefaultVariant();
    $variantB = makeProduct("TRACKR-B-$suffix")->getDefaultVariant();

    $shipments = $plugin->getShipments();
    $order = makeOrder([['variant' => $variantA, 'qty' => 2], ['variant' => $variantB, 'qty' => 1]]);

    check('an order with nothing recorded has an empty state', function() use ($shipments, $order) {
        $state = $shipments->getOrderState((int)$order->id);

        return $state instanceof OrderState && $state->getHasShipped() === false;
    });

    check('the shippable quantity counts every unit', function() use ($shipments, $order) {
        return $shipments->getShippableQty($order) === 3 ?: 'got ' . $shipments->getShippableQty($order);
    });

    check('a shipment is recorded', function() use ($shipments, $order, $suffix) {
        $result = $shipments->record($order, [
            'provider' => 'ups',
            'trackingNumber' => "1Z999AA1012345678$suffix",
            'service' => 'Ground',
            'items' => [['lineItemId' => $order->getLineItems()[0]->id, 'qty' => 1]],
            'source' => Shipment::SOURCE_CP,
        ]);

        return $result['shipment'] instanceof Shipment && $result['isNew'] === true
            ?: 'errors: ' . json_encode($result['errors']);
    });

    check('the recorded shipment carries a resolved carrier and URL', function() use ($shipments, $order) {
        $shipment = $shipments->getShipmentsForOrder((int)$order->id)[0];

        return $shipment->providerHandle === 'ups'
            && $shipment->getProviderLabel() === 'UPS'
            && str_contains((string)$shipment->getTrackingUrl(), 'ups.com');
    });

    check('one item of three leaves the order partly shipped', function() use ($shipments, $order) {
        $progress = $shipments->getProgress($order);

        return $progress['shipped'] === 1 && $progress['total'] === 3 && $progress['fullyShipped'] === false
            ?: json_encode($progress);
    });

    check('the same tracking number does not land twice', function() use ($shipments, $order, $suffix) {
        $result = $shipments->record($order, [
            'provider' => 'ups',
            'trackingNumber' => "1Z999AA1012345678$suffix",
            'service' => 'Ground Saver',
        ]);

        return $result['isNew'] === false && count($shipments->getShipmentsForOrder((int)$order->id)) === 1
            ?: 'shipments: ' . count($shipments->getShipmentsForOrder((int)$order->id));
    });

    check('re-sending updates the shipment it matched', function() use ($shipments, $order) {
        return $shipments->getShipmentsForOrder((int)$order->id)[0]->service === 'Ground Saver';
    });

    check('the same number under a different carrier is a different shipment', function() use ($shipments, $order, $suffix) {
        $result = $shipments->record($order, [
            'provider' => 'fedex',
            'trackingNumber' => "1Z999AA1012345678$suffix",
            'items' => [['lineItemId' => $order->getLineItems()[0]->id, 'qty' => 1]],
        ]);

        return $result['isNew'] === true && count($shipments->getShipmentsForOrder((int)$order->id)) === 2;
    });

    check('item quantities are capped at what the line item holds', function() use ($shipments, $order) {
        // The single-unit line, whichever position Commerce put it in.
        $single = null;

        foreach ($order->getLineItems() as $lineItem) {
            if ((int)$lineItem->qty === 1) {
                $single = $lineItem;
                break;
            }
        }

        $result = $shipments->record($order, [
            'provider' => 'usps',
            'trackingNumber' => 'CAPPED-' . random_int(1000, 9999),
            'items' => [['lineItemId' => $single->id, 'qty' => 99]],
        ]);

        return $result['shipment']->getQty() === 1 ?: 'got ' . $result['shipment']->getQty();
    });

    check('a line item from another order is ignored', function() use ($shipments, $order) {
        $result = $shipments->record($order, [
            'provider' => 'usps',
            'trackingNumber' => 'FOREIGN-' . random_int(1000, 9999),
            'items' => [['lineItemId' => 99999999, 'qty' => 1]],
        ]);

        // With no valid items it falls back to covering the remainder rather than claiming a
        // line item that is not on this order.
        return $result['shipment']->getItems() === [];
    });

    check('a shipment with neither a number nor a carrier is refused', function() use ($shipments, $order) {
        $result = $shipments->record($order, ['provider' => '', 'trackingNumber' => '']);

        return $result['shipment'] === null && $result['errors'] !== [];
    });

    $soloOrder = makeOrder([['variant' => $variantA, 'qty' => 2]]);

    check('an order-level shipment covers whatever is left', function() use ($shipments, $soloOrder) {
        $result = $shipments->record($soloOrder, [
            'provider' => 'dhl-express',
            'trackingNumber' => 'SOLO-' . random_int(1000, 9999),
        ]);

        return $result['fullyShipped'] === true && $result['shipment']->getQty() === 2
            ?: 'qty ' . $result['shipment']->getQty();
    });

    $partialOrder = makeOrder([['variant' => $variantA, 'qty' => 3]]);

    check('a shipment flagged partial leaves the order open', function() use ($shipments, $partialOrder) {
        $result = $shipments->record($partialOrder, [
            'provider' => 'ups',
            'trackingNumber' => 'PARTIAL-' . random_int(1000, 9999),
            'partial' => true,
        ]);

        return $result['fullyShipped'] === false && $result['shipment']->getQty() === 0;
    });

    check('a later order-level shipment closes the partly-shipped order', function() use ($shipments, $partialOrder) {
        $result = $shipments->record($partialOrder, [
            'provider' => 'ups',
            'trackingNumber' => 'PARTIAL-REST-' . random_int(1000, 9999),
        ]);

        return $result['fullyShipped'] === true ?: 'still open';
    });

    check('unshipped line item quantities are reported for the picker', function() use ($shipments, $order) {
        $remaining = $shipments->getUnshippedLineItemQtys($order);

        return array_sum($remaining) >= 0 && count($remaining) === 2 ?: json_encode($remaining);
    });

    check('deleting a shipment gives its units back', function() use ($shipments, $variantA) {
        $deleteOrder = makeOrder([['variant' => $variantA, 'qty' => 2]]);
        $result = $shipments->record($deleteOrder, [
            'provider' => 'ups',
            'trackingNumber' => 'DELETE-' . random_int(1000, 9999),
        ]);

        $before = $shipments->getProgress($deleteOrder)['shipped'];
        $shipments->deleteShipmentById((int)$result['shipment']->id);
        $after = $shipments->getProgress($deleteOrder)['shipped'];

        return $before === 2 && $after === 0 ?: "before $before, after $after";
    });

    check('a cancelled shipment stops counting', function() use ($shipments, $variantA) {
        $cancelOrder = makeOrder([['variant' => $variantA, 'qty' => 2]]);
        $result = $shipments->record($cancelOrder, [
            'provider' => 'ups',
            'trackingNumber' => 'CANCEL-' . random_int(1000, 9999),
        ]);

        $before = $shipments->getProgress($cancelOrder)['shipped'];
        $shipments->updateStatus($result['shipment'], Shipment::STATUS_CANCELLED);
        $after = $shipments->getProgress($cancelOrder)['shipped'];

        return $before === 2 && $after === 0 ?: "before $before, after $after";
    });

    check('a shipment can be marked delivered', function() use ($shipments, $soloOrder) {
        $shipment = $shipments->getShipmentsForOrder((int)$soloOrder->id)[0];
        $shipments->updateStatus($shipment, Shipment::STATUS_DELIVERED, 'Left in the porch');

        $reloaded = $shipments->getShipmentById((int)$shipment->id);

        return $reloaded->status === Shipment::STATUS_DELIVERED
            && $reloaded->dateDelivered instanceof DateTime
            && $reloaded->statusDetail === 'Left in the porch';
    });

    check('an order whose every shipment is delivered is delivered', function() use ($shipments, $soloOrder) {
        return $shipments->getOrderState((int)$soloOrder->id)->getIsDelivered() === true;
    });

    check('an unknown status is refused', function() use ($shipments, $soloOrder) {
        $shipment = $shipments->getShipmentsForOrder((int)$soloOrder->id)[0];

        return $shipments->updateStatus($shipment, 'teleported') === false;
    });

    check('the rollup is rebuilt from the rows, not incremented', function() use ($shipments, $order) {
        Craft::$app->getDb()->createCommand()
            ->update(Table::ORDERSTATE, ['shippedQty' => 999], ['orderId' => $order->id])
            ->execute();

        $state = $shipments->recalculateOrderState($order);

        return $state->shippedQty !== 999 ?: 'stale count survived a recalculation';
    });

    check('a shipment key is stable for the same carrier and number', function() use ($shipments) {
        $a = $shipments->buildShipmentKey('1Z999', 'UPS');
        $b = $shipments->buildShipmentKey('1z999', 'ups');

        return $a === $b ?: "$a vs $b";
    });

    check('a shipment with no number gets a unique key', function() use ($shipments) {
        $a = $shipments->buildShipmentKey('', 'customer-pickup');
        $b = $shipments->buildShipmentKey('', 'customer-pickup');

        return $a !== $b;
    });

    // ---------------------------------------------------------------------
    section('Order statuses');

    $statuses = $plugin->getStatuses();

    check('the status options list Commerce statuses', function() use ($statuses) {
        return is_array($statuses->getStatusOptions());
    });

    check('no configured status means the order is left alone', function() use ($statuses, $plugin) {
        applySettings(['shippedStatusHandle' => '']);

        return $statuses->targetStatusHandle(new OrderState(['shipmentCount' => 1, 'fullyShipped' => true]), true) === null;
    });

    $existingStatus = array_key_first($statuses->getStatusOptions());

    if ($existingStatus !== null) {
        check('a fully shipped order targets the shipped status', function() use ($statuses, $existingStatus) {
            applySettings(['shippedStatusHandle' => $existingStatus]);

            return $statuses->targetStatusHandle(new OrderState(['shipmentCount' => 1, 'fullyShipped' => true]), true) === $existingStatus;
        });

        check('a partly shipped order with no partial status is left alone on Pro', function() use ($statuses) {
            applySettings(['partiallyShippedStatusHandle' => '', 'partialShipmentsEnabled' => true]);

            return $statuses->targetStatusHandle(new OrderState(['shipmentCount' => 1, 'fullyShipped' => false]), true) === null;
        });

        check('a partly shipped order marks shipped on Lite', function() use ($statuses, $existingStatus) {
            return $statuses->targetStatusHandle(new OrderState(['shipmentCount' => 1, 'fullyShipped' => false]), false) === $existingStatus;
        });

        check('an order with nothing shipped is never moved', function() use ($statuses) {
            return $statuses->targetStatusHandle(new OrderState(['shipmentCount' => 0]), true) === null;
        });

        check('recording tracking moves the order to the shipped status', function() use ($shipments, $variantA, $existingStatus, $statuses) {
            applySettings(['shippedStatusHandle' => $existingStatus, 'updateStatusOnTracking' => true]);

            $target = $statuses->getStatusByHandle($existingStatus);
            $movedOrder = makeOrder([['variant' => $variantA, 'qty' => 1]]);

            $shipments->record($movedOrder, ['provider' => 'ups', 'trackingNumber' => 'MOVE-' . random_int(1000, 9999)]);

            $reloaded = Order::find()->id($movedOrder->id)->status(null)->one();

            return (int)$reloaded->orderStatusId === (int)$target->id
                ?: 'status is ' . $reloaded->orderStatusId . ', expected ' . $target->id;
        });

        check('an order-history note is written without a status change', function() use ($shipments, $variantA, $existingStatus) {
            applySettings(['shippedStatusHandle' => $existingStatus, 'addOrderHistoryNote' => true]);

            $noteOrder = makeOrder([['variant' => $variantA, 'qty' => 1]]);
            $shipments->record($noteOrder, ['provider' => 'ups', 'trackingNumber' => 'NOTE-A-' . random_int(1000, 9999)]);
            // The second shipment cannot change the status again, so only the note can record it.
            $shipments->record($noteOrder, ['provider' => 'fedex', 'trackingNumber' => 'NOTE-B-' . random_int(1000, 9999)]);

            $histories = Commerce::getInstance()->getOrderHistories()->getAllOrderHistoriesByOrderId((int)$noteOrder->id);
            $notes = array_filter($histories, static fn($history) => str_contains((string)$history->message, 'tracking'));

            return count($notes) >= 1 ?: 'no tracking note on the order history';
        });
    } else {
        echo "  ! no Commerce order statuses configured — status checks skipped\n";
    }

    check('a status that does not exist resolves to null rather than throwing', function() use ($statuses) {
        return $statuses->getStatusByHandle('definitely-not-a-status') === null;
    });

    applySettings(['shippedStatusHandle' => '', 'updateStatusOnTracking' => false]);

    // ---------------------------------------------------------------------
    section('CSV import');

    $imports = $plugin->getImports();
    $importOrder = makeOrder([['variant' => $variantA, 'qty' => 1]]);
    $reference = (string)$importOrder->reference;

    check('a sample CSV is produced', fn() => str_contains($imports->sampleCsv(), 'Tracking Number'));

    check('headers are matched loosely', function() use ($imports, $reference) {
        $path = writeCsv("Order #,TRACKING-NO,Courier\n$reference,IMP1234567,UPS\n");
        $parsed = $imports->parse($path);

        return isset($parsed['map']['orderNumber'], $parsed['map']['trackingNumber'], $parsed['map']['provider'])
            ?: 'mapped ' . json_encode($parsed['map']);
    });

    check('a semicolon-delimited file is read', function() use ($imports, $reference) {
        $path = writeCsv("Order Number;Tracking Number;Carrier\n$reference;SEMI123;UPS\n");
        $parsed = $imports->parse($path);

        return ($parsed['rows'][0]['trackingNumber'] ?? null) === 'SEMI123' ?: json_encode($parsed);
    });

    check('a UTF-8 BOM does not break the first column', function() use ($imports, $reference) {
        $path = writeCsv("\xEF\xBB\xBFOrder Number,Tracking Number\n$reference,BOM123\n");
        $parsed = $imports->parse($path);

        return isset($parsed['map']['orderNumber']) ?: 'mapped ' . json_encode($parsed['map']);
    });

    check('a file with no order number column is refused', function() use ($imports) {
        $path = writeCsv("Tracking Number,Carrier\nX1,UPS\n");

        return $imports->parse($path)['error'] !== null;
    });

    check('a missing file is refused', fn() => $imports->parse('/tmp/definitely-not-here.csv')['error'] !== null);

    check('preview resolves orders without writing anything', function() use ($imports, $shipments, $importOrder, $reference) {
        $path = writeCsv("Order Number,Tracking Number,Carrier\n$reference,PREVIEW123,UPS\n");
        $preview = $imports->preview($path);

        return $preview['summary']['ready'] === 1
            && count($shipments->getShipmentsForOrder((int)$importOrder->id)) === 0
            ?: 'ready=' . $preview['summary']['ready'];
    });

    check('preview flags a row whose order does not exist', function() use ($imports) {
        $path = writeCsv("Order Number,Tracking Number,Carrier\nNOT-AN-ORDER,X1,UPS\n");
        $preview = $imports->preview($path);

        return $preview['summary']['problems'] === 1 && $preview['rows'][0]['errors'] !== [];
    });

    check('an unknown carrier is a warning, not a failure', function() use ($imports, $reference) {
        $path = writeCsv("Order Number,Tracking Number,Carrier\n$reference,X2,Cormorant Post\n");
        $preview = $imports->preview($path);

        return $preview['rows'][0]['errors'] === [] && $preview['rows'][0]['warnings'] !== []
            ?: json_encode($preview['rows'][0]);
    });

    check('an import writes the rows it previewed', function() use ($imports, $shipments, $importOrder, $reference) {
        $path = writeCsv("Order Number,Tracking Number,Carrier,Ship Date,Service\n$reference,IMPORTED1,UPS,2026-08-14,Ground\n");
        $result = $imports->import($path);

        return $result['imported'] === 1 && count($shipments->getShipmentsForOrder((int)$importOrder->id)) === 1
            ?: json_encode($result);
    });

    check('the imported shipment kept its ship date and service', function() use ($shipments, $importOrder) {
        $shipment = $shipments->getShipmentsForOrder((int)$importOrder->id)[0];

        return $shipment->service === 'Ground' && $shipment->shipDate?->format('Y-m-d') === '2026-08-14'
            ?: 'date ' . var_export($shipment->shipDate?->format('Y-m-d'), true);
    });

    check('re-importing the same file updates rather than duplicates', function() use ($imports, $shipments, $importOrder, $reference) {
        $path = writeCsv("Order Number,Tracking Number,Carrier,Service\n$reference,IMPORTED1,UPS,Next Day\n");
        $result = $imports->import($path);

        return $result['updated'] === 1
            && $result['imported'] === 0
            && count($shipments->getShipmentsForOrder((int)$importOrder->id)) === 1
            ?: json_encode($result);
    });

    check('a status column is honoured', function() use ($imports, $shipments, $variantA) {
        $statusOrder = makeOrder([['variant' => $variantA, 'qty' => 1]]);
        $path = writeCsv("Order Number,Tracking Number,Carrier,Status\n{$statusOrder->reference},STATUSED1,USPS,delivered\n");
        $imports->import($path);

        return $shipments->getShipmentsForOrder((int)$statusOrder->id)[0]->status === Shipment::STATUS_DELIVERED;
    });

    check('an unrecognised status falls back to the default', function() use ($imports, $shipments, $variantA, $plugin) {
        $statusOrder = makeOrder([['variant' => $variantA, 'qty' => 1]]);
        $path = writeCsv("Order Number,Tracking Number,Carrier,Status\n{$statusOrder->reference},STATUSED2,USPS,teleported\n");
        $imports->import($path);

        return $shipments->getShipmentsForOrder((int)$statusOrder->id)[0]->status === $plugin->getSettings()->defaultShipmentStatus;
    });

    check('an upload stash round-trips under a generated token', function() use ($imports) {
        $path = writeCsv("Order Number,Tracking Number\nX,Y\n");
        $token = $imports->stashUpload($path);
        $resolved = $imports->stashedPath((string)$token);
        $imports->discardStash((string)$token);

        return $token !== null && $resolved !== null && $imports->stashedPath((string)$token) === null;
    });

    check('a stash token that is not a UUID resolves to nothing', function() use ($imports) {
        return $imports->stashedPath('../../../etc/passwd') === null;
    });

    // ---------------------------------------------------------------------
    section('Customer lookup');

    $tracking = $plugin->getTracking();
    $lookupOrder = makeOrder([['variant' => $variantA, 'qty' => 1]], true, "lookup-$suffix@example.com");

    check('an order is found by reference and matching email', function() use ($tracking, $lookupOrder, $suffix) {
        $result = $tracking->lookup((string)$lookupOrder->reference, "lookup-$suffix@example.com", null);

        return $result['order']?->id === $lookupOrder->id
            ?: 'error: ' . var_export($result['error'], true);
    });

    check('a wrong email finds nothing', function() use ($tracking, $lookupOrder) {
        $result = $tracking->lookup((string)$lookupOrder->reference, 'someone@else.test', null);

        return $result['order'] === null && $result['error'] !== null;
    });

    check('a wrong email answers the same way as a wrong order number', function() use ($tracking, $lookupOrder) {
        $wrongEmail = $tracking->lookup((string)$lookupOrder->reference, 'someone@else.test', null);
        $wrongOrder = $tracking->lookup('NOT-AN-ORDER', 'someone@else.test', null);

        return $wrongEmail['error'] === $wrongOrder['error'] ?: 'the two failures are distinguishable';
    });

    check('a wildcard order number is refused rather than matching everything', function() use ($tracking) {
        return $tracking->findOrder('*') === null;
    });

    check('a comma in the order number is refused', fn() => $tracking->findOrder('1,2') === null);

    check('an emailed token finds the order without an email address', function() use ($tracking, $lookupOrder) {
        return $tracking->lookupByToken($lookupOrder->uid)?->id === $lookupOrder->id;
    });

    check('a junk token finds nothing', fn() => $tracking->lookupByToken('not-a-uid') === null);

    check('repeated failures throttle a client', function() use ($tracking, $plugin) {
        $client = 'checks-' . random_int(10000, 99999);
        applySettings(['trackingMaxAttempts' => 3, 'trackingAttemptWindow' => 600]);

        for ($i = 0; $i < 3; $i++) {
            $tracking->lookup('NOT-AN-ORDER', 'nobody@example.test', $client);
        }

        $throttled = $tracking->lookup('NOT-AN-ORDER', 'nobody@example.test', $client)['throttled'];
        $tracking->clearThrottle($client);
        applySettings(['trackingMaxAttempts' => 10]);

        return $throttled === true ?: 'not throttled after three failures';
    });

    check('an order number with no email is refused when email is required', function() use ($tracking, $lookupOrder) {
        $result = $tracking->lookup((string)$lookupOrder->reference, '', null);

        return $result['order'] === null;
    });

    check('email can be waived deliberately', function() use ($tracking, $lookupOrder) {
        applySettings(['trackingRequireEmail' => false]);
        $result = $tracking->lookup((string)$lookupOrder->reference, '', null);
        applySettings(['trackingRequireEmail' => true]);

        return $result['order']?->id === $lookupOrder->id;
    });

    // ---------------------------------------------------------------------
    section('Widget');

    $widget = $plugin->getWidget();

    check('the widget renders an order’s shipments', function() use ($widget, $importOrder) {
        $html = (string)$widget->render($importOrder);

        return str_contains($html, 'IMPORTED1') ?: 'got ' . substr($html, 0, 200);
    });

    check('the widget links the tracking number', function() use ($widget, $importOrder) {
        return str_contains((string)$widget->render($importOrder), 'ups.com');
    });

    check('the widget is empty for an order with nothing on it', function() use ($widget, $variantA) {
        $emptyOrder = makeOrder([['variant' => $variantA, 'qty' => 1]]);

        return trim((string)$widget->render($emptyOrder)) === '';
    });

    check('the widget renders every layout', function() use ($widget) {
        foreach (['card', 'list', 'table'] as $layout) {
            $html = (string)$widget->renderShipments(null, $widget->sampleShipments(), ['widgetLayout' => $layout]);

            if (!str_contains($html, '1Z999AA10123456784')) {
                return "layout $layout rendered nothing";
            }
        }

        return true;
    });

    check('preview overrides do not touch the saved settings', function() use ($widget, $plugin) {
        $before = $plugin->getSettings()->widgetAccentColor;
        $widget->renderShipments(null, $widget->sampleShipments(), ['widgetAccentColor' => '#FF0000']);

        return $plugin->getSettings()->widgetAccentColor === $before;
    });

    check('a preview override reaches the markup', function() use ($widget) {
        $html = (string)$widget->renderShipments(null, $widget->sampleShipments(), ['widgetAccentColor' => '#FF00AA']);

        return str_contains($html, '#FF00AA');
    });

    check('a checkbox override posted as a string is read as a boolean', function() use ($widget) {
        $off = (string)$widget->renderShipments(null, $widget->sampleShipments(), ['widgetShowTrackButton' => '0']);

        return !str_contains($off, 'Track shipment');
    });

    // ---------------------------------------------------------------------
    section('Twig API');

    $variable = new TrackrVariable();

    check('shipments() reads an order’s shipments', fn() => count($variable->shipments($importOrder)) === 1);
    check('shipments() accepts a bare id', fn() => count($variable->shipments((int)$importOrder->id)) === 1);
    check('shipments() on null is empty', fn() => $variable->shipments(null) === []);
    check('latest() returns a shipment', fn() => $variable->latest($importOrder) instanceof Shipment);
    check('hasShipped() is true once something ships', fn() => $variable->hasShipped($importOrder) === true);
    check('isDelivered() is true for a delivered order', fn() => $variable->isDelivered($soloOrder) === true);

    check('progress() reports the maths', function() use ($variable, $order) {
        $progress = $variable->progress($order);

        return isset($progress['shipped'], $progress['total'], $progress['remaining'], $progress['percent']);
    });

    check('progress() on null is all zeroes', function() use ($variable) {
        return $variable->progress(null)['total'] === 0;
    });

    check('trackingUrl() builds a link', function() use ($variable) {
        return str_contains((string)$variable->trackingUrl('fedex', '123456789012'), 'fedex.com');
    });

    check('providers() lists the enabled carriers', fn() => count($variable->providers()) > 100);
    check('provider() finds one by handle', fn() => $variable->provider('usps')?->name === 'USPS');
    check('detect() finds a carrier from a number', fn() => $variable->detect('1Z999AA10123456784')?->handle === 'ups');
    check('statuses() lists the delivery statuses', fn() => count($variable->statuses()) === 7);
    check('trackingPageUrl() returns a URL', fn() => str_contains($variable->trackingPageUrl($importOrder), 'track'));

    check('orderNumber() always names the order somehow', function() use ($variable, $importOrder) {
        return $variable->orderNumber($importOrder) !== '';
    });

    check('orderNumber() on null is an empty string', fn() => $variable->orderNumber(null) === '');
    check('widget() returns markup', fn() => str_contains((string)$variable->widget($importOrder), 'IMPORTED1'));

    // ---------------------------------------------------------------------
    section('Log');

    $log = $plugin->getLog();

    check('recording a shipment writes a log row', function() use ($log) {
        return $log->getEntryCount(['action' => 'shipment.create']) > 0;
    });

    check('log entries come back as models', function() use ($log) {
        $entries = $log->getEntries([], 5);

        return $entries === [] || $entries[0] instanceof \justinholtweb\trackr\models\LogEntry;
    });

    check('the log can be searched', function() use ($log) {
        return is_array($log->getEntries(['search' => 'order'], 5));
    });

    check('pruning with a huge window deletes nothing', fn() => $log->prune(3650) === 0);

    check('logging can be switched off', function() use ($log, $plugin, $shipments, $variantA) {
        applySettings(['loggingEnabled' => false]);
        $before = $log->getEntryCount([]);

        $quietOrder = makeOrder([['variant' => $variantA, 'qty' => 1]]);
        $shipments->record($quietOrder, ['provider' => 'ups', 'trackingNumber' => 'QUIET-' . random_int(1000, 9999)]);

        $after = $log->getEntryCount([]);
        applySettings(['loggingEnabled' => true]);

        return $before === $after ?: "log grew from $before to $after with logging off";
    });

    // ---------------------------------------------------------------------
    section('Push API (live HTTP)');

    $client = Craft::createGuzzleClient([
        'base_uri' => 'http://localhost/',
        'timeout' => 30,
        'http_errors' => false,
    ]);

    $token = $plugin->getSettings()->getParsedApiToken();
    $apiOrder = makeOrder([['variant' => $variantA, 'qty' => 2]]);

    check('an unauthenticated request is rejected', function() use ($client) {
        $response = $client->post('actions/trackr/api/shipments', ['json' => ['order_number' => 'x']]);

        return $response->getStatusCode() === 401
            ?: 'got ' . $response->getStatusCode() . ': ' . substr((string)$response->getBody(), 0, 200);
    });

    check('a wrong token is rejected', function() use ($client) {
        $response = $client->post('actions/trackr/api/shipments', [
            'headers' => ['Authorization' => 'Bearer nope'],
            'json' => ['order_number' => 'x'],
        ]);

        return $response->getStatusCode() === 401 ?: 'got ' . $response->getStatusCode();
    });

    check('a bearer token is accepted and records a shipment', function() use ($client, $token, $apiOrder, $plugin) {
        $response = $client->post('actions/trackr/api/shipments', [
            'headers' => ['Authorization' => "Bearer $token"],
            'json' => [
                'order_number' => (string)$apiOrder->reference,
                'tracking_number' => 'API-0001',
                'carrier' => 'ups',
                'service' => 'Ground',
            ],
        ]);

        $body = json_decode((string)$response->getBody(), true);

        return $response->getStatusCode() === 201
            && ($body['success'] ?? false) === true
            && count($plugin->getShipments()->getShipmentsForOrder((int)$apiOrder->id)) === 1
            ?: 'got ' . $response->getStatusCode() . ': ' . substr((string)$response->getBody(), 0, 300);
    });

    check('a retried push updates rather than duplicating', function() use ($client, $token, $apiOrder, $plugin) {
        $response = $client->post('actions/trackr/api/shipments', [
            'headers' => ['Authorization' => "Bearer $token"],
            'json' => [
                'order_number' => (string)$apiOrder->reference,
                'tracking_number' => 'API-0001',
                'carrier' => 'ups',
            ],
        ]);

        return $response->getStatusCode() === 200
            && count($plugin->getShipments()->getShipmentsForOrder((int)$apiOrder->id)) === 1
            ?: 'got ' . $response->getStatusCode() . ' with '
                . count($plugin->getShipments()->getShipmentsForOrder((int)$apiOrder->id)) . ' shipments';
    });

    check('the X-Trackr-Token header works where Authorization is stripped', function() use ($client, $token, $apiOrder) {
        $response = $client->post('actions/trackr/api/shipments', [
            'headers' => ['X-Trackr-Token' => $token],
            'json' => [
                'order_number' => (string)$apiOrder->reference,
                'tracking_number' => 'API-0002',
                'carrier' => 'fedex',
            ],
        ]);

        return $response->getStatusCode() === 201 ?: 'got ' . $response->getStatusCode();
    });

    check('a trackr_token query parameter works too', function() use ($client, $token, $apiOrder) {
        $response = $client->get('actions/trackr/api/shipments', [
            'query' => ['order_number' => (string)$apiOrder->reference, 'trackr_token' => $token],
        ]);

        $body = json_decode((string)$response->getBody(), true);

        return $response->getStatusCode() === 200 && count($body['shipments'] ?? []) === 2
            ?: 'got ' . $response->getStatusCode() . ': ' . substr((string)$response->getBody(), 0, 300);
    });

    check('an unknown order is a 404', function() use ($client, $token) {
        $response = $client->post('actions/trackr/api/shipments', [
            'headers' => ['Authorization' => "Bearer $token"],
            'json' => ['order_number' => 'NOT-AN-ORDER', 'tracking_number' => 'X'],
        ]);

        return $response->getStatusCode() === 404 ?: 'got ' . $response->getStatusCode();
    });

    check('the status endpoint marks a shipment delivered', function() use ($client, $token, $apiOrder, $plugin) {
        $response = $client->post('actions/trackr/api/status', [
            'headers' => ['Authorization' => "Bearer $token"],
            'json' => ['tracking_number' => 'API-0001', 'status' => 'delivered'],
        ]);

        $shipments = $plugin->getShipments()->getShipmentsForOrder((int)$apiOrder->id);
        $delivered = array_filter($shipments, static fn(Shipment $s) => $s->trackingNumber === 'API-0001' && $s->getIsDelivered());

        return $response->getStatusCode() === 200 && count($delivered) === 1
            ?: 'got ' . $response->getStatusCode() . ': ' . substr((string)$response->getBody(), 0, 300);
    });

    check('the status endpoint refuses an unknown status', function() use ($client, $token) {
        $response = $client->post('actions/trackr/api/status', [
            'headers' => ['Authorization' => "Bearer $token"],
            'json' => ['tracking_number' => 'API-0001', 'status' => 'teleported'],
        ]);

        return $response->getStatusCode() === 400 ?: 'got ' . $response->getStatusCode();
    });

    check('the carriers endpoint lists handles', function() use ($client, $token) {
        $response = $client->get('actions/trackr/api/carriers', ['query' => ['trackr_token' => $token]]);
        $body = json_decode((string)$response->getBody(), true);

        return $response->getStatusCode() === 200 && count($body['carriers'] ?? []) > 100
            ?: 'got ' . $response->getStatusCode();
    });

    check('the API can be switched off entirely', function() use ($client, $token) {
        applySettings(['apiEnabled' => false]);

        $response = $client->get('actions/trackr/api/carriers', ['query' => ['trackr_token' => $token]]);

        applySettings(['apiEnabled' => true]);

        return $response->getStatusCode() === 403 ?: 'got ' . $response->getStatusCode();
    });

    check('an API with no token configured rejects everything', function() use ($client, $token) {
        applySettings(['apiToken' => '']);

        $response = $client->get('actions/trackr/api/carriers', ['query' => ['trackr_token' => $token]]);

        applySettings(['apiToken' => $token]);

        return $response->getStatusCode() === 403 ?: 'got ' . $response->getStatusCode();
    });

    // ---------------------------------------------------------------------
    section('Tracking page (live HTTP)');

    check('the tracking page is served', function() use ($client) {
        $response = $client->get('track');

        return $response->getStatusCode() === 200 && str_contains((string)$response->getBody(), 'Order number')
            ?: 'got ' . $response->getStatusCode();
    });

    check('the tracking page tells search engines to stay away', function() use ($client) {
        return str_contains((string)$client->get('track')->getBody(), 'noindex');
    });

    check('a tracking token deep-links to the order', function() use ($client, $apiOrder) {
        $response = $client->get('track', ['query' => ['t' => $apiOrder->uid]]);

        return $response->getStatusCode() === 200 && str_contains((string)$response->getBody(), 'API-0001')
            ?: 'got ' . $response->getStatusCode() . ': ' . substr((string)$response->getBody(), 0, 300);
    });

    check('a junk token shows the form and an error, not an order', function() use ($client) {
        $response = $client->get('track', ['query' => ['t' => 'nope']]);
        $body = (string)$response->getBody();

        return $response->getStatusCode() === 200 && !str_contains($body, 'API-0001');
    });

    check('the page can be switched off', function() use ($client) {
        applySettings(['trackingPageEnabled' => false]);

        $response = $client->get('track');

        applySettings(['trackingPageEnabled' => true]);

        return $response->getStatusCode() === 404 ?: 'got ' . $response->getStatusCode();
    });

    // ---------------------------------------------------------------------
    section('Lite');

    switchEdition(Plugin::EDITION_LITE);
    $plugin->getProviders()->clearCaches();

    check('the plugin reports Lite', fn() => $plugin->isPro() === false);

    check('auto-detection is a Pro feature', fn() => $plugin->getProviders()->detect('1Z999AA10123456784') === null);

    check('item-level shipments collapse to order-level on Lite', function() use ($shipments, $variantA) {
        $liteOrder = makeOrder([['variant' => $variantA, 'qty' => 3]]);
        $result = $shipments->record($liteOrder, [
            'provider' => 'ups',
            'trackingNumber' => 'LITE-' . random_int(1000, 9999),
            'items' => [['lineItemId' => $liteOrder->getLineItems()[0]->id, 'qty' => 1]],
        ]);

        return $result['shipment']->getItems() === [] && $result['fullyShipped'] === true
            ?: 'items ' . json_encode($result['shipment']->getItems());
    });

    check('the partial flag is ignored on Lite', function() use ($shipments, $variantA) {
        $liteOrder = makeOrder([['variant' => $variantA, 'qty' => 2]]);
        $result = $shipments->record($liteOrder, [
            'provider' => 'ups',
            'trackingNumber' => 'LITEPART-' . random_int(1000, 9999),
            'partial' => true,
        ]);

        return $result['fullyShipped'] === true;
    });

    check('the push API is refused on Lite', function() use ($client, $token) {
        $response = $client->get('actions/trackr/api/carriers', ['query' => ['trackr_token' => $token]]);

        return $response->getStatusCode() === 403 ?: 'got ' . $response->getStatusCode();
    });

    check('watched-folder import is refused on Lite', function() use ($plugin) {
        $result = $plugin->getImports()->watch('/tmp');

        return $result['files'] === 0 && $result['messages'] !== [];
    });

    check('shipment emails are refused on Lite', function() use ($plugin, $importOrder, $shipments) {
        $shipment = $shipments->getShipmentsForOrder((int)$importOrder->id)[0];

        return $plugin->getNotifications()->sendShipmentEmail($importOrder, $shipment) === false;
    });

    check('Lite keeps a short log tail regardless of the setting', function() use ($plugin) {
        return $plugin->getSettings()->getEffectiveLogRetentionDays() === 7;
    });

    check('the tracking page still works on Lite', function() use ($client) {
        return $client->get('track')->getStatusCode() === 200;
    });

    check('CSV import still works on Lite', function() use ($imports, $shipments, $variantA) {
        $liteOrder = makeOrder([['variant' => $variantA, 'qty' => 1]]);
        $path = writeCsv("Order Number,Tracking Number,Carrier\n{$liteOrder->reference},LITECSV1,USPS\n");
        $result = $imports->import($path);

        return $result['imported'] === 1 ?: json_encode($result);
    });

    check('switching back to Pro restores the Pro behaviour', function() use ($plugin) {
        switchEdition(Plugin::EDITION_PRO);

        return $plugin->isPro() === true;
    });
} finally {
    section('Cleanup');

    $elements = Craft::$app->getElements();

    foreach ($createdOrders as $fixtureOrder) {
        try {
            Craft::$app->getDb()->createCommand()->delete(Table::SHIPMENTS, ['orderId' => $fixtureOrder->id])->execute();
            Craft::$app->getDb()->createCommand()->delete(Table::ORDERSTATE, ['orderId' => $fixtureOrder->id])->execute();
            $elements->deleteElement($fixtureOrder, true);
        } catch (Throwable $e) {
            echo "  ! could not delete order {$fixtureOrder->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($createdProducts as $fixtureProduct) {
        try {
            $elements->deleteElement($fixtureProduct, true);
        } catch (Throwable $e) {
            echo "  ! could not delete product {$fixtureProduct->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($tempFiles as $file) {
        @unlink($file);
    }

    try {
        Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    } catch (Throwable $e) {
        echo "  ! could not clear the log: {$e->getMessage()}\n";
    }

    try {
        applySettings($originalSettings);
    } catch (Throwable $e) {
        echo "  ! could not restore settings: {$e->getMessage()}\n";
    }

    try {
        switchEdition($originalEdition);
    } catch (Throwable $e) {
        echo "  ! could not restore the plugin edition: {$e->getMessage()}\n";
    }

    echo "  ✓ fixtures removed, settings restored\n";

    echo "\n" . str_repeat('-', 60) . "\n";
    echo "  $passed passed, $failed failed\n";
    echo str_repeat('-', 60) . "\n";
}

exit($failed > 0 ? 1 : 0);
