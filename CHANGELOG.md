# Release Notes for Trackr

## 5.0.1 - 2026-10-06

> {warning} Pruning and clearing the activity log now needs the new **Prune and clear the activity
> log** permission; **View the activity log** alone no longer allows it. Tracking URLs that aren't
> `http://` or `https://` are no longer stored or shown, and a custom carrier's URL template has to
> be one. If your site is behind a proxy or CDN, set Craft's `trustedHosts` so the tracking page's
> limit counts real visitors rather than the proxy.

### Security
- A tracking URL was stored and rendered exactly as it arrived, from the push API, a CSV import or
  the order screen, so a `javascript:` URL ran for whoever clicked the tracking number in the order
  panel, the tracking page or an email. Only http(s) URLs are now stored, every link is checked
  again when it's shown (including ones stored earlier and ones built from a carrier template), and
  carrier URL and logo templates have to be http(s) to save.
- Anyone who could read the activity log could prune or clear it, including the API's record of
  rejected sign-ins. That now needs **Prune and clear the activity log**.
- Every API request with a bad token wrote a log row, with no limit. A client that gets the token
  wrong 10 times in a minute is now refused for two minutes before its token is checked, under a
  site-wide ceiling as well, and rejections are logged once per client per minute.
- With **Require the email on the order** off, the tracking page accepted sequential order IDs and
  only counted failed lookups, so it could be used to read every order in turn. The public page no
  longer looks orders up by ID, and without the email requirement every lookup counts toward the
  limit. The limit also counted by `getUserIP()`, which believes `X-Forwarded-For` from anyone; it
  now counts the connecting address, and reads forwarded headers only from proxies in `trustedHosts`.

### Changed
- The control panel screens use a stylesheet on Craft's CSS variables instead of inline styles and
  hard-coded colours. Emails and the customer tracking page keep their own styling.

## 5.0.0

Initial release.

### Added

- Tracking on Commerce's own order edit screen: multiple tracking numbers per order, carrier
  picker, ship date, delivery status, and a note.
- 130 built-in carriers with tracking URL templates, across North America, Europe, Asia-Pacific,
  South America, Africa, the Middle East, LTL freight, print-on-demand and the aggregators.
- Carrier management: switch any built-in carrier out of the picker, and test its tracking URL
  before trusting it.
- Shipped-status advancement, with Trackr able to create the **Shipped**, **Partially shipped**
  and **Delivered** order statuses Commerce does not ship with.
- Order-history notes recording the carrier and tracking number, written even when the status
  does not move.
- The tracking widget — inline-styled, email-client-safe markup for order emails and account
  pages, with a live preview on the settings screen and a template override.
- A customer-facing tracking page for guests, with per-IP throttling and identical answers for
  every failed lookup, so it cannot be used to enumerate order numbers.
- CSV import with loose header matching and a full preview before anything is written.
- `craft.trackr.*` Twig API: `widget()`, `shipments()`, `latest()`, `hasShipped()`,
  `isFullyShipped()`, `isDelivered()`, `progress()`, `trackingUrl()`, `trackingPageUrl()`,
  `providers()`, `detect()`.
- Console commands for adding, listing, showing and re-statusing shipments, importing CSVs and
  pruning the log.
- `EVENT_AFTER_SAVE_SHIPMENT` and `EVENT_AFTER_UPDATE_STATUS`.
- **Pro:** item-level tracking — line items and quantities per shipment, with shippable-unit
  counting and a partially-shipped status.
- **Pro:** carrier auto-detection from the shape of a tracking number, which declines to guess
  when more than one carrier matches.
- **Pro:** custom and white-labelled carriers, with their own names, logos, URL templates and
  detection patterns.
- **Pro:** push API for fulfilment services, with bearer-token auth, an `X-Trackr-Token` or
  `trackr_token` fallback for servers that strip the `Authorization` header, and idempotent writes.
- **Pro:** watched-folder CSV import for cron, archiving files once read.
- **Pro:** delivery status tracking, a delivered order status, and shipment and delivery
  notification emails.
- **Pro:** activity log with payloads, filtering and retention.
