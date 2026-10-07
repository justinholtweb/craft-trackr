<?php
/**
 * Trackr's links, its log, its API sign-in and its public lookup — checked in the plugin-testing
 * harness, mostly over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-trackr/tests/integration/security.php
 *
 * Until 5.0.1: a `javascript:` tracking URL (from the API, a CSV or a carrier template) was stored
 * and rendered as a link; anyone who could read the log could erase it; every bad API token wrote
 * a log row, unthrottled; and with the email requirement off, the public lookup accepted sequential
 * order IDs and only counted misses, so it could be walked. The lookup's throttle also keyed on
 * getUserIP(), which believes X-Forwarded-For from anyone.
 *
 * Sets an API token and switches the email requirement off for the run; settings are restored in a
 * fresh process (see craft-nuke's tests/README). Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\trackr\Plugin;
use justinholtweb\trackr\records\ShipmentRecord;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

// craft-penny's broken beforeSaveElement handler (see craft-bird's checks.php) — detached in-process only.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$plugin->isPro() or throw new RuntimeException('Trackr must be on Pro in the harness for this run.');
$run = bin2hex(random_bytes(3));
$password = 'Trackr-' . bin2hex(random_bytes(6));
$token = 'trackr-sec-' . bin2hex(random_bytes(16));
$settingsPath = 'plugins.trackr.settings';
$settingsBefore = Craft::$app->getProjectConfig()->get($settingsPath);
$cleanup = ['users' => [], 'orders' => [], 'products' => []];

Craft::$app->getPlugins()->savePluginSettings($plugin, array_merge($plugin->getSettings()->toArray(), [
    'apiEnabled' => true, 'apiToken' => $token, 'trackingPageEnabled' => true, 'trackingRequireEmail' => false,
    'loggingEnabled' => true, 'trackingMaxAttempts' => 5, 'trackingAttemptWindow' => 600, 'customProviders' => [],
]));
Craft::$app->getProjectConfig()->saveModifiedConfigData();
Craft::$app->getProjectConfig()->writeYamlFiles(true);
Craft::$app->getCache()->flush();

register_shutdown_function(function() use (&$cleanup, $settingsPath, $settingsBefore, $root) {
    foreach ($cleanup['orders'] as $order) {
        ShipmentRecord::deleteAll(['orderId' => $order->id]);
        Craft::$app->getElements()->deleteElement($order, true);
    }
    foreach ($cleanup['products'] as $product) {
        Craft::$app->getElements()->deleteElement($product, true);
    }
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
    Craft::$app->getCache()->flush();

    $restore = sys_get_temp_dir() . '/trackr-restore-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($restore, '<?php
require ' . var_export($root . '/bootstrap.php', true) . ';
$app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
$pc = Craft::$app->getProjectConfig();
$pc->set(' . var_export($settingsPath, true) . ', ' . var_export($settingsBefore, true) . ', "Restore Trackr settings after security.php");
$pc->saveModifiedConfigData();
$pc->writeYamlFiles(true);
');
    exec('php ' . escapeshellarg($restore) . ' 2>&1', $out, $code);
    unlink($restore);
    $code === 0 or print("  ! Could not restore Trackr's settings: " . implode("\n", $out) . "\n");
});

// --- an order to ship -------------------------------------------------------------------------

$type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];
$product = new Product(['typeId' => $type->id, 'title' => "Trackr sec $run", 'enabled' => true]);
$product->setVariants([new Variant(['sku' => "TRK-$run", 'basePrice' => 10, 'weight' => 1, 'isDefault' => true])]);
Craft::$app->getElements()->saveElement($product) or throw new RuntimeException(json_encode($product->getErrors()));
$cleanup['products'][] = $product;
$variant = Variant::find()->sku("TRK-$run")->status(null)->one();

$makeOrder = static function() use ($variant, &$cleanup): Order {
    $commerce = Commerce::getInstance();
    $order = new Order(['storeId' => $commerce->getStores()->getPrimaryStore()->id, 'orderLanguage' => Craft::$app->language, 'currency' => $commerce->getStores()->getPrimaryStore()->getCurrency()]);
    $order->setEmail('buyer@example.com');
    Craft::$app->getElements()->saveElement($order) or throw new RuntimeException(json_encode($order->getErrors()));
    $order->addLineItem($commerce->getLineItems()->create($order, ['purchasableId' => $variant->id, 'qty' => 1]));
    Craft::$app->getElements()->saveElement($order);
    $order->markAsComplete();
    $cleanup['orders'][] = $order;

    return Order::find()->id($order->id)->status(null)->one();
};

$order = $makeOrder();
$shipments = $plugin->getShipments();

echo "\nTracking URLs\n";

$urls = [
    'javascript' => 'javascript:alert(document.cookie)',
    'mixed case' => 'JaVaScRiPt:alert(1)',
    'leading space' => '  javascript:alert(1)',
    'data' => 'data:text/html,<script>alert(1)</script>',
    'no scheme' => '//evil.example/track',
];

foreach ($urls as $label => $url) {
    check("a $label URL is not stored", function() use ($shipments, $order, $url, $run) {
        $result = $shipments->record($order, ['trackingNumber' => "1Z$run" . md5($url), 'trackingUrl' => $url]);
        $row = ShipmentRecord::find()->where(['orderId' => $order->id])->orderBy(['id' => SORT_DESC])->one();

        return $row !== null && $row->trackingUrl === null ?: 'stored as ' . var_export($row?->trackingUrl, true) . ' ' . json_encode($result);
    });
}

check('an https URL is stored', function() use ($shipments, $order, $run) {
    $shipments->record($order, ['trackingNumber' => "OK$run", 'trackingUrl' => ' https://carrier.example/t/OK ']);
    $row = ShipmentRecord::find()->where(['orderId' => $order->id, 'trackingNumber' => "OK$run"])->one();

    return $row?->trackingUrl === 'https://carrier.example/t/OK' ?: 'stored as ' . var_export($row?->trackingUrl, true);
});

check('a URL already stored is never rendered unless it is http(s)', function() use ($shipments, $order, $run) {
    $shipments->record($order, ['trackingNumber' => "OLD$run"]);
    // As a row written before 5.0.1 would hold it.
    Craft::$app->getDb()->createCommand()->update('{{%trackr_shipments}}', ['trackingUrl' => 'javascript:alert(1)'], ['orderId' => $order->id, 'trackingNumber' => "OLD$run"])->execute();
    $shipment = array_values(array_filter($shipments->getShipmentsForOrder((int)$order->id), fn($s) => $s->trackingNumber === "OLD$run"))[0];

    return $shipment->getTrackingUrl() === null ?: 'rendered ' . $shipment->getTrackingUrl();
});

check('…including from a carrier template', function() use ($plugin, $run) {
    Craft::$app->getPlugins()->savePluginSettings($plugin, array_merge($plugin->getSettings()->toArray(), ['customProviders' => [
        "evil$run" => ['name' => "Evil $run", 'url' => 'javascript:alert({tracking_number})', 'country' => '*', 'match' => [], 'needs' => [], 'enabled' => true, 'sortOrder' => 0],
    ]]));
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
    $plugin->getProviders()->clearCaches();
    $url = $plugin->getProviders()->trackingUrl("evil$run", '12345');

    return $url === null ?: "built $url";
});

echo "\nThe control panel\n";

$client = static function(string $username, string $password): array {
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');
    $http->post('index.php?p=actions/users/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode() === 200
        or throw new RuntimeException("Could not sign in as $username");

    return [$http, $csrf];
};

[$admin, $adminCsrf] = $client('admin', 'claudepassword');

check('a carrier template that isn’t http(s) can’t be saved', function() use ($admin, $adminCsrf, $run) {
    $response = $admin->post('index.php?p=admin/actions/trackr/providers/save', [
        'headers' => ['Accept' => 'application/json'],
        'form_params' => ['isCustom' => '1', 'name' => "Bad $run", 'url' => 'javascript:alert({tracking_number})', 'CRAFT_CSRF_TOKEN' => $adminCsrf()],
    ]);
    $saved = isset(Plugin::getInstance()->getSettings()->customProviders[strtolower("bad$run")]);

    return $response->getStatusCode() >= 400 && !$saved ?: 'status ' . $response->getStatusCode();
});

$user = static function(string $name, array $permissions) use (&$cleanup, $run, $password): User {
    $u = new User(['username' => "trackr-$name-$run", 'email' => "trackr-$name-$run@example.com", 'newPassword' => $password]);
    Craft::$app->getElements()->saveElement($u, false);
    Craft::$app->getUsers()->activateUser($u);
    Craft::$app->getUserPermissions()->saveUserPermissions($u->id, $permissions);
    $cleanup['users'][] = $u;

    return $u;
};

$reader = $user('reader', ['accesscp', 'accessplugin-trackr', 'trackr-viewlog']);
$keeper = $user('keeper', ['accesscp', 'accessplugin-trackr', 'trackr-viewlog', 'trackr-managelog']);
$logRows = static fn() => (int)(new Query())->from('{{%trackr_log}}')->count();

check('reading the log doesn’t let you erase it', function() use ($client, $reader, $password, $logRows) {
    [$http, $csrf] = $client($reader->username, $password);
    $before = $logRows();
    $prune = $http->post('index.php?p=admin/actions/trackr/log/prune', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode();
    $clear = $http->post('index.php?p=admin/actions/trackr/log/clear', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode();

    return $prune === 403 && $clear === 403 && $logRows() === $before ?: "prune $prune, clear $clear";
});

check('“Prune and clear the activity log” can prune it', function() use ($client, $keeper, $password) {
    [$http, $csrf] = $client($keeper->username, $password);
    $status = $http->post('index.php?p=admin/actions/trackr/log/prune', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode();

    return $status === 200 ?: "status $status";
});

check('the control panel screens render without inline styles or hard-coded colours', function() use ($admin, $order) {
    $problems = [];
    foreach (['trackr/shipments', 'trackr/providers', 'trackr/log', 'trackr/import', 'settings/plugins/trackr', "commerce/orders/{$order->id}"] as $uri) {
        $response = $admin->get("index.php?p=admin/$uri");
        if (!in_array($response->getStatusCode(), [200, 302], true)) {
            $problems[] = "$uri: " . $response->getStatusCode();
        }
    }
    foreach (glob(dirname(__DIR__, 2) . '/src/templates/{settings.twig,_order-panel.twig,providers/*.twig,shipments/*.twig,log/*.twig,import/*.twig}', GLOB_BRACE) as $file) {
        $twig = file_get_contents($file);
        if (str_contains($twig, 'style="') || preg_match('/#[0-9a-f]{3,6}\b/i', $twig)) {
            $problems[] = basename(dirname($file)) . '/' . basename($file);
        }
    }

    return $problems === [] ?: implode(', ', $problems);
});

echo "\nThe API\n";

check('repeated bad tokens are shut out, and logged once', function() use ($logRows) {
    Craft::$app->getCache()->flush();
    $before = $logRows();
    $api = new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]);
    $codes = [];
    for ($i = 0; $i < 14; $i++) {
        $codes[] = $api->post('index.php?p=actions/trackr/api/shipments', ['json' => ['order_number' => 'x'], 'headers' => ['Authorization' => 'Bearer wrong-' . $i]])->getStatusCode();
    }
    $added = $logRows() - $before;

    return in_array(429, $codes, true) && $codes[0] === 401 && $added === 1 ?: json_encode(['codes' => $codes, 'rows added' => $added]);
});

check('…while the right token still works for a client in good standing', function() use ($token, $order) {
    Craft::$app->getCache()->flush();
    $api = new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]);
    $status = $api->post('index.php?p=actions/trackr/api/shipments', [
        'json' => ['order_number' => (string)$order->reference ?: $order->number, 'tracking_number' => 'APIOK' . time(), 'tracking_url' => 'javascript:alert(1)'],
        'headers' => ['Authorization' => "Bearer $token"],
    ])->getStatusCode();
    $row = ShipmentRecord::find()->where(['orderId' => $order->id])->orderBy(['id' => SORT_DESC])->one();

    return in_array($status, [200, 201], true) && $row?->trackingUrl === null ?: "status $status, url " . var_export($row?->trackingUrl, true);
});

echo "\nThe public tracking page\n";

$page = static function(string $orderNumber, array $headers = []): string {
    return (string)(new Client(['base_uri' => 'http://localhost/', 'http_errors' => false, 'headers' => $headers]))
        ->get('index.php?p=track&orderNumber=' . rawurlencode($orderNumber))->getBody();
};
$notFound = 'find an order with those details';
$throttled = 'Too many attempts';

check('an order’s numeric ID doesn’t open it', function() use ($page, $order, $notFound) {
    Craft::$app->getCache()->flush();

    return str_contains($page((string)$order->id), $notFound) ?: 'the ID opened the order';
});

check('without the email requirement, every lookup counts — hits as well as misses', function() use ($page, $order, $throttled) {
    Craft::$app->getCache()->flush();
    $number = $order->reference ?: substr($order->number, 0, 7);
    $limit = Plugin::getInstance()->getSettings()->trackingMaxAttempts;
    $seen = [];
    for ($i = 0; $i < $limit + 2; $i++) {
        $seen[] = str_contains($page($number), $throttled) ? 'throttled' : 'ok';
    }

    return in_array('throttled', $seen, true) && $seen[0] === 'ok' ?: implode(',', $seen);
});

check('a new X-Forwarded-For doesn’t reset the count', function() use ($page, $throttled) {
    Craft::$app->getCache()->flush();
    $limit = Plugin::getInstance()->getSettings()->trackingMaxAttempts;
    $throttledAt = null;
    for ($i = 0; $i < $limit + 3; $i++) {
        if (str_contains($page('NOPE' . $i, ['X-Forwarded-For' => '198.51.100.' . ($i + 1)]), $throttled)) {
            $throttledAt = $i;
            break;
        }
    }
    Craft::$app->getCache()->flush();

    return $throttledAt !== null ?: 'never throttled';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
