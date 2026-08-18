<?php

namespace justinholtweb\trackr\models;

use craft\base\Model;
use craft\helpers\StringHelper;

/**
 * A shipping provider: one of the built-in carriers from `src/data/providers.php`, or a custom
 * one the merchant defined in settings.
 *
 * A provider is a value object, not a record. Built-ins live in code so they can be corrected in
 * a release; the merchant's changes to them live in settings as overrides.
 */
class Provider extends Model
{
    public string $handle = '';
    public string $name = '';

    /**
     * Tracking URL template. Supported placeholders:
     * `{tracking_number}`, `{postal_code}`, `{phone}`, `{country}`, `{ship_date}`.
     */
    public string $url = '';

    /**
     * ISO country code the carrier ships from, `*` for global.
     */
    public string $country = '*';

    /**
     * Regular expressions that identify a tracking number as this carrier.
     *
     * @var string[]
     */
    public array $match = [];

    /**
     * Placeholders the URL needs beyond the tracking number.
     *
     * @var string[]
     */
    public array $needs = [];

    /**
     * Absolute URL of a logo to show in the tracking widget.
     */
    public ?string $logoUrl = null;

    /**
     * True when the merchant defined this provider rather than Trackr shipping it.
     */
    public bool $isCustom = false;

    /**
     * True when a built-in provider has been overridden in settings.
     */
    public bool $isOverridden = false;

    public bool $enabled = true;

    public int $sortOrder = 0;

    /**
     * Build a provider from a registry or settings row.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(string $handle, array $config): self
    {
        return new self([
            'handle' => $handle,
            'name' => (string)($config['name'] ?? $handle),
            'url' => (string)($config['url'] ?? ''),
            'country' => (string)($config['country'] ?? '*'),
            'match' => array_values((array)($config['match'] ?? [])),
            'needs' => array_values((array)($config['needs'] ?? [])),
            'logoUrl' => ($config['logoUrl'] ?? null) ?: null,
            'isCustom' => (bool)($config['isCustom'] ?? false),
            'enabled' => (bool)($config['enabled'] ?? true),
            'sortOrder' => (int)($config['sortOrder'] ?? 0),
        ]);
    }

    /**
     * Reduce a name or handle to a comparable key: "DHL Express", "dhl_express" and "DHL-Express"
     * all become "dhlexpress". Tracking data arrives from CSVs and third-party APIs that never
     * agree on punctuation.
     */
    public static function normalizeKey(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', $value));
    }

    /**
     * Whether this provider can produce a link at all. "Customer pickup" deliberately cannot.
     */
    public function hasUrl(): bool
    {
        return trim($this->url) !== '';
    }

    /**
     * Fill the URL template.
     *
     * @param array<string, string|null> $context
     */
    public function buildUrl(string $trackingNumber, array $context = []): ?string
    {
        $trackingNumber = trim($trackingNumber);

        if (!$this->hasUrl() || $trackingNumber === '') {
            return null;
        }

        $replacements = [
            '{tracking_number}' => rawurlencode($trackingNumber),
            '{postal_code}' => rawurlencode((string)($context['postal_code'] ?? '')),
            '{phone}' => rawurlencode((string)($context['phone'] ?? '')),
            '{country}' => rawurlencode((string)($context['country'] ?? '')),
            '{ship_date}' => rawurlencode((string)($context['ship_date'] ?? '')),
        ];

        return strtr($this->url, $replacements);
    }

    /**
     * Whether a tracking number looks like this carrier's.
     */
    public function matches(string $trackingNumber): bool
    {
        $candidate = strtoupper(preg_replace('/\s+/', '', $trackingNumber) ?? '');

        if ($candidate === '') {
            return false;
        }

        foreach ($this->match as $pattern) {
            // A malformed pattern from a custom provider must not take the site down.
            if (@preg_match($pattern, $candidate) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRegistryArray(): array
    {
        return [
            'name' => $this->name,
            'url' => $this->url,
            'country' => $this->country,
            'match' => $this->match,
            'needs' => $this->needs,
            'logoUrl' => $this->logoUrl,
            'isCustom' => $this->isCustom,
            'enabled' => $this->enabled,
            'sortOrder' => $this->sortOrder,
        ];
    }

    /**
     * A safe handle for a merchant-typed provider name.
     */
    public static function handleFromName(string $name): string
    {
        $handle = StringHelper::toKebabCase(trim($name));

        return $handle !== '' ? $handle : 'custom';
    }
}
