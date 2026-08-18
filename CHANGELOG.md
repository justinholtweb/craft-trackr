# Release Notes for Trackr

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
