<?php

namespace justinholtweb\trackr\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use justinholtweb\trackr\helpers\Urls;
use justinholtweb\trackr\models\Provider;
use justinholtweb\trackr\models\Shipment;
use justinholtweb\trackr\Plugin;
use Throwable;

/**
 * The shipping provider registry.
 *
 * **The invariant:** `trackingUrl()` is the only place a tracking URL is built. The CP link, the
 * email widget, the customer tracking page and the Twig API all resolve through it, so they
 * cannot show a customer three different links for one parcel.
 *
 * Built-in carriers live in `src/data/providers.php` so a wrong URL can be corrected in a
 * release. Merchant changes are stored as *overrides* rather than copies, so that correction
 * still reaches a merchant who only renamed the carrier.
 */
class Providers extends Component
{
    /**
     * @var array<string, Provider>|null
     */
    private ?array $_providers = null;

    /**
     * @var array<string, string>|null Normalised name => handle.
     */
    private ?array $_nameIndex = null;

    /**
     * Every provider Trackr knows about, merchant changes applied, in picker order.
     *
     * @return array<string, Provider>
     */
    public function getAllProviders(): array
    {
        if ($this->_providers !== null) {
            return $this->_providers;
        }

        $settings = Plugin::getInstance()->getSettings();
        $registry = $this->getBuiltInRegistry();
        $providers = [];

        foreach ($registry as $handle => $config) {
            $provider = Provider::fromArray($handle, $config);

            $override = $settings->providerOverrides[$handle] ?? null;

            if (is_array($override) && $override !== []) {
                $provider->isOverridden = true;

                foreach (['name', 'url', 'logoUrl', 'country'] as $key) {
                    if (isset($override[$key]) && trim((string)$override[$key]) !== '') {
                        $provider->$key = (string)$override[$key];
                    }
                }

                if (isset($override['sortOrder'])) {
                    $provider->sortOrder = (int)$override['sortOrder'];
                }

                if (isset($override['enabled'])) {
                    $provider->enabled = (bool)$override['enabled'];
                }
            }

            $providers[$handle] = $provider;
        }

        // Custom providers are added last so a merchant can shadow a built-in handle deliberately.
        foreach ($settings->customProviders as $handle => $config) {
            if (!is_array($config)) {
                continue;
            }

            $handle = (string)$handle;

            if ($handle === '') {
                continue;
            }

            $config['isCustom'] = true;
            $providers[$handle] = Provider::fromArray($handle, $config);
        }

        // An explicit enabled list narrows the picker; an empty list means "all of them".
        if ($settings->enabledProviders !== []) {
            $allowed = array_flip($settings->enabledProviders);

            foreach ($providers as $handle => $provider) {
                if (!isset($allowed[$handle]) && !$provider->isCustom) {
                    $provider->enabled = false;
                }
            }
        }

        $this->_providers = $this->sortProviders($providers, $settings->preferredCountry);

        return $this->_providers;
    }

    /**
     * Only the providers offered in pickers.
     *
     * @return array<string, Provider>
     */
    public function getEnabledProviders(): array
    {
        return array_filter($this->getAllProviders(), static fn(Provider $p) => $p->enabled);
    }

    public function getProviderByHandle(?string $handle): ?Provider
    {
        if ($handle === null || $handle === '') {
            return null;
        }

        return $this->getAllProviders()[$handle] ?? null;
    }

    /**
     * Find a provider from whatever a CSV row, an API payload or a human typed: a handle, a
     * display name, or something close enough to one once punctuation is discarded.
     */
    public function resolve(?string $value): ?Provider
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $provider = $this->getProviderByHandle($value);

        if ($provider !== null) {
            return $provider;
        }

        if ($this->_nameIndex === null) {
            $this->_nameIndex = [];

            foreach ($this->getAllProviders() as $handle => $candidate) {
                $this->_nameIndex[Provider::normalizeKey($candidate->name)] ??= $handle;
                $this->_nameIndex[Provider::normalizeKey($handle)] ??= $handle;
            }
        }

        $key = Provider::normalizeKey($value);
        $handle = $this->_nameIndex[$key] ?? null;

        if ($handle !== null) {
            return $this->getAllProviders()[$handle];
        }

        // "UPS Ground Saver" and the like: fall back to the longest known key it starts with, so
        // a service name still lands on its carrier.
        $best = null;
        $bestLength = 0;

        foreach ($this->_nameIndex as $candidateKey => $candidateHandle) {
            $length = strlen($candidateKey);

            if ($length > 2 && $length > $bestLength && str_starts_with($key, $candidateKey)) {
                $best = $candidateHandle;
                $bestLength = $length;
            }
        }

        return $best !== null ? $this->getAllProviders()[$best] : null;
    }

    /**
     * Work the carrier out from the shape of the tracking number (Pro).
     *
     * Returns null rather than a guess when more than one carrier claims the number — putting a
     * customer on the wrong carrier's website is worse than showing plain text.
     */
    public function detect(?string $trackingNumber): ?Provider
    {
        if ($trackingNumber === null || trim($trackingNumber) === '') {
            return null;
        }

        if (!Plugin::getInstance()->isPro()) {
            return null;
        }

        $hits = [];

        foreach ($this->getAllProviders() as $provider) {
            if (!$provider->enabled) {
                continue;
            }

            if ($provider->matches($trackingNumber)) {
                $hits[] = $provider;
            }
        }

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * The tracking URL for a shipment. **The only place a URL is built.**
     */
    public function trackingUrlForShipment(Shipment $shipment): ?string
    {
        // A URL typed against this one shipment always wins: it is the merchant telling Trackr
        // where the parcel actually is.
        // Checked again here, not only on the way in: a row stored before 5.0.1 may hold anything.
        if ($shipment->trackingUrl !== null && trim($shipment->trackingUrl) !== '') {
            return Urls::webUrlOrNull($shipment->trackingUrl);
        }

        $provider = $shipment->getProvider();

        if ($provider === null || $shipment->trackingNumber === null) {
            return null;
        }

        $context = [];

        // Only load the order when the template actually needs something off it — otherwise a
        // shipment list would fetch one order per row.
        if ($provider->needs !== [] && $shipment->orderId !== null) {
            $context = $this->contextForOrder($shipment->orderId, $shipment);
        }

        return Urls::webUrlOrNull($provider->buildUrl($shipment->trackingNumber, $context));
    }

    /**
     * A URL from a provider and number alone, for callers that have no shipment row.
     */
    public function trackingUrl(?string $providerValue, ?string $trackingNumber, array $context = []): ?string
    {
        if ($trackingNumber === null || trim($trackingNumber) === '') {
            return null;
        }

        $provider = $this->resolve($providerValue) ?? $this->detect($trackingNumber);

        return Urls::webUrlOrNull($provider?->buildUrl($trackingNumber, $context));
    }

    /**
     * Options for a provider dropdown, with the blank "no carrier" choice up top.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function getSelectOptions(bool $includeBlank = true): array
    {
        $options = [];

        if ($includeBlank) {
            $options[] = ['label' => Craft::t('trackr', 'Choose a carrier…'), 'value' => ''];
        }

        foreach ($this->getEnabledProviders() as $handle => $provider) {
            $options[] = ['label' => $provider->name, 'value' => $handle];
        }

        return $options;
    }

    /**
     * Save a merchant-defined provider (Pro).
     *
     * @param array<string, mixed> $config
     */
    public function saveCustomProvider(string $handle, array $config): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        $handle = $handle !== '' ? $handle : Provider::handleFromName((string)($config['name'] ?? ''));

        $custom = $settings->customProviders;
        $custom[$handle] = [
            'name' => (string)($config['name'] ?? $handle),
            'url' => (string)($config['url'] ?? ''),
            'country' => (string)($config['country'] ?? '*'),
            'logoUrl' => ($config['logoUrl'] ?? null) ?: null,
            'match' => array_values(array_filter((array)($config['match'] ?? []))),
            'needs' => array_values(array_filter((array)($config['needs'] ?? []))),
            'enabled' => (bool)($config['enabled'] ?? true),
            'sortOrder' => (int)($config['sortOrder'] ?? 0),
        ];

        $saved = $this->persist(['customProviders' => $custom]);

        $this->clearCaches();

        return $saved;
    }

    public function deleteCustomProvider(string $handle): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        $custom = $settings->customProviders;

        if (!isset($custom[$handle])) {
            return false;
        }

        unset($custom[$handle]);

        $saved = $this->persist(['customProviders' => $custom]);

        $this->clearCaches();

        return $saved;
    }

    /**
     * Store an override against a built-in provider.
     *
     * @param array<string, mixed> $values
     */
    public function saveOverride(string $handle, array $values): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        $registry = $this->getBuiltInRegistry();

        if (!isset($registry[$handle])) {
            return false;
        }

        $overrides = $settings->providerOverrides;
        $values = array_filter(
            $values,
            static fn($value, $key) => in_array($key, ['name', 'url', 'logoUrl', 'country', 'enabled', 'sortOrder'], true),
            ARRAY_FILTER_USE_BOTH
        );

        if ($values === []) {
            unset($overrides[$handle]);
        } else {
            $overrides[$handle] = $values;
        }

        $saved = $this->persist(['providerOverrides' => $overrides]);

        $this->clearCaches();

        return $saved;
    }

    /**
     * Save settings changes.
     *
     * Craft writes plugin settings into project config as `toArray(array_keys($settings))` and
     * `set()`s the whole node — so handing it only the keys that changed would silently reset
     * every *other* setting to its default on the next request. Everything goes across, always.
     *
     * @param array<string, mixed> $changes
     */
    private function persist(array $changes): bool
    {
        $plugin = Plugin::getInstance();

        return Craft::$app->getPlugins()->savePluginSettings(
            $plugin,
            array_merge($plugin->getSettings()->toArray(), $changes)
        );
    }

    /**
     * The untouched built-in registry.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getBuiltInRegistry(): array
    {
        static $registry = null;

        if ($registry === null) {
            $registry = require dirname(__DIR__) . '/data/providers.php';
        }

        return $registry;
    }

    public function clearCaches(): void
    {
        $this->_providers = null;
        $this->_nameIndex = null;
    }

    /**
     * Placeholder values a carrier's URL may need, read off the order's shipping address.
     *
     * @return array<string, string>
     */
    private function contextForOrder(int $orderId, Shipment $shipment): array
    {
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            return [];
        }

        $address = $order->getShippingAddress();
        $phone = '';

        if ($address !== null) {
            // Craft 5 moved addresses out of Commerce and dropped the phone attribute, so a
            // phone number is a custom field that may simply not be there.
            try {
                $phone = (string)($address->getFieldValue('phone') ?? '');
            } catch (Throwable) {
                $phone = '';
            }
        }

        return [
            'postal_code' => (string)($address->postalCode ?? ''),
            'country' => (string)($address->countryCode ?? ''),
            'phone' => $phone,
            'ship_date' => $shipment->shipDate?->format('Y-m-d') ?? '',
        ];
    }

    /**
     * @param array<string, Provider> $providers
     * @return array<string, Provider>
     */
    private function sortProviders(array $providers, string $preferredCountry): array
    {
        $preferred = strtoupper(trim($preferredCountry));

        uasort($providers, static function(Provider $a, Provider $b) use ($preferred) {
            if ($a->sortOrder !== $b->sortOrder) {
                return $b->sortOrder <=> $a->sortOrder;
            }

            if ($preferred !== '') {
                $aPreferred = strtoupper($a->country) === $preferred ? 0 : 1;
                $bPreferred = strtoupper($b->country) === $preferred ? 0 : 1;

                if ($aPreferred !== $bPreferred) {
                    return $aPreferred <=> $bPreferred;
                }
            }

            return strcasecmp($a->name, $b->name);
        });

        return $providers;
    }
}
