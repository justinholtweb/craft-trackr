<?php

namespace justinholtweb\trackr\helpers;

/**
 * Which URLs Trackr will put in a link.
 *
 * Tracking URLs arrive from places Trackr doesn't control — the push API, a CSV, a carrier
 * template — and are rendered as `href`s in the order panel, on the customer's tracking page and in
 * emails. Until 5.0.1 a `javascript:` URL was stored and rendered as given, and ran for the admin
 * who clicked the tracking number.
 */
final class Urls
{
    /**
     * Whether `$url` is an absolute http(s) URL. Leading and trailing space is ignored; anything a
     * browser might read as a script scheme is not an http(s) URL and fails.
     */
    public static function isWebUrl(?string $url): bool
    {
        $url = trim((string)$url);

        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }

        return preg_match('~^https?://[^\s/?#]+~i', $url) === 1;
    }

    /** `$url` if it is one, otherwise null. */
    public static function webUrlOrNull(?string $url): ?string
    {
        return self::isWebUrl($url) ? trim((string)$url) : null;
    }
}
