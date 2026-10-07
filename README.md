# Trackr

Shipment tracking for Craft Commerce 5. Tracking numbers get onto orders from wherever you
fulfil, and turn into something a customer can actually follow — in their order email, on their
account page, and on a tracking page that needs no account at all.

Trackr is carrier-agnostic on purpose. It does not connect to one label service and lock you to
it: tracking arrives by hand, by CSV, or over an API, and 130 carriers' tracking URLs are already
built in.

## Requirements

- Craft CMS 5.3+
- Craft Commerce 5.0+
- PHP 8.2+

## Installation

```sh
composer require justinholtweb/craft-trackr
php craft plugin/install trackr
```

Then open **Settings → Plugins → Trackr**, pick (or have Trackr create) the order statuses a
shipped order should move to, and add `{{ craft.trackr.widget(order) }}` to your Commerce order
email.

## Editions

| | Lite (free) | Pro |
|---|---|---|
| **Price** | **Free** | **$99**, $49/year renewal |
| Tracking on Commerce's own order screen | ✅ | ✅ |
| 130 built-in carriers with tracking links | ✅ | ✅ |
| Multiple tracking numbers per order | ✅ | ✅ |
| Shipped-status advancement + order history notes | ✅ | ✅ |
| Tracking widget for emails and account pages | ✅ | ✅ |
| Customer tracking page, no account needed | ✅ | ✅ |
| CSV import with a preview before it writes | ✅ | ✅ |
| Shipments index, carrier list, console commands | ✅ | ✅ |
| `craft.trackr.*` Twig API | ✅ | ✅ |
| **Item-level tracking** — tracking per line item and quantity | — | ✅ |
| **Partial shipments** — a partly-shipped status until the rest goes | — | ✅ |
| **Carrier auto-detection** from the tracking number | — | ✅ |
| **Custom and white-labelled carriers** | — | ✅ |
| **Push API** for 3PLs, ERPs and label services | — | ✅ |
| **Watched-folder CSV import** on a schedule | — | ✅ |
| **Delivery status** and delivery notification emails | — | ✅ |
| **Activity log** with payloads | — | ✅ |

## Getting tracking in

Four ways in, one way through. Everything lands in `Shipments::record()`, so the same tracking
number sent twice updates one shipment rather than creating a second — which matters, because
fulfilment services retry and merchants re-upload spreadsheets.

### By hand

Trackr adds a panel to Commerce's own order edit screen. Pick a carrier, paste the number, done.
On Pro you can also say which line items and quantities are in the box.

### By CSV

**Trackr → Import.** Upload the file and Trackr reads the header row, works out which column is
which, resolves every order and carrier, and shows you exactly what it would do. Nothing is
written until you say so.

Headers are matched loosely, so a supplier's `Tracking No`, `tracking_number` and
`TRACKING-NUMBER` are all the same column. The only required column is the order number.

```csv
Order Number,Tracking Number,Carrier,Ship Date,Service,Status
CT-1042,1Z999AA10123456784,UPS,2026-08-14,Ground,in_transit
CT-1043,9400111899223197428490,USPS,2026-08-14,Priority Mail,delivered
```

On Pro, `php craft trackr/import/watch` imports everything in a watched folder and moves the
files aside — point a supplier's SFTP drop at it and put the command on cron.

### By API (Pro)

```sh
curl -X POST https://example.com/actions/trackr/api/shipments \
  -H 'Authorization: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{
        "order_number": "CT-1042",
        "tracking_number": "1Z999AA10123456784",
        "carrier": "ups",
        "service": "Ground",
        "ship_date": "2026-08-14"
      }'
```

`201` for a new shipment, `200` when it updated one that already existed. `GET` the same URL with
`?order_number=` to read tracking back, `POST /actions/trackr/api/status` to move a shipment to
`delivered`, and `GET /actions/trackr/api/carriers` for the handles Trackr understands.

Apache commonly strips the `Authorization` header, so `X-Trackr-Token` and a `trackr_token`
query parameter are accepted too. (Not `token` — that one is Craft's own preview-token parameter,
and Craft rejects the request before the plugin sees it.) With no token configured the endpoint rejects everything.

### From the command line

```sh
php craft trackr/shipments/add CT-1042 1Z999AA10123456784 --carrier=ups
php craft trackr/shipments/show CT-1042
php craft trackr/import/csv tracking.csv --dry-run
php craft trackr/log/prune
```

## Getting tracking out

### In an email

```twig
{{ craft.trackr.widget(order) }}
```

That is the whole integration. The widget is table markup with inline styles, because it mostly
lives in email clients that strip `<style>` blocks and never load an external sheet. Colours,
layout and what it shows are all settings, with a live preview on the settings screen.

Point `widgetTemplate` at a site template to replace the markup entirely; it gets the same
variables.

### On an account page

The same call. `craft.trackr.progress(order)` gives you `{shipped, total, remaining, percent}` if
you would rather build your own.

### On a tracking page

Trackr serves a customer-facing page at `/track` (configurable). A customer enters their order
number and the email address on the order; an emailed link carries the order's UID and skips the
form. Failed lookups are throttled per IP and every failure answers identically, so the page
cannot be used to find out which order numbers exist.

## Twig API

```twig
{{ craft.trackr.widget(order) }}          {# the tracking widget #}
{% for shipment in craft.trackr.shipments(order) %}
    {{ shipment.getProviderLabel() }} — {{ shipment.trackingNumber }}
    {{ shipment.getTrackingUrl() }}
    {{ shipment.getStatusLabel() }}
{% endfor %}

{{ craft.trackr.latest(order).trackingNumber }}
{{ craft.trackr.hasShipped(order) }}
{{ craft.trackr.isFullyShipped(order) }}
{{ craft.trackr.isDelivered(order) }}
{{ craft.trackr.progress(order).percent }}
{{ craft.trackr.trackingPageUrl(order) }}
{{ craft.trackr.trackingUrl('ups', '1Z999AA10123456784') }}
{{ craft.trackr.detect('1Z999AA10123456784').name }}   {# Pro #}
```

## Carriers

130 carriers ship with Trackr, across North America, Europe, Asia-Pacific, South America, Africa
and the Middle East, plus LTL freight, print-on-demand and the tracking aggregators.

**Trackr → Carriers** lists them. Switch off the ones you never use so the picker stays short. On
Pro you can rename them, point them at a different URL, give them a logo for the widget, and add
your own — including a white-labelled "Our delivery van" with no URL at all, which records a
number without pretending it links anywhere.

Tracking URLs are templates: `{tracking_number}`, and where a carrier needs it,
`{postal_code}`, `{phone}`, `{country}` and `{ship_date}`.

A carrier Trackr does not recognise is still recorded — the number just shows as plain text. That
is deliberate: a link that 404s is worse than no link.

## Order statuses

Commerce has no shipped status of its own, so Trackr asks which of yours to use, and will create
**Shipped**, **Partially shipped** and **Delivered** for you from the settings screen if you have
not built them.

- Everything shipped → the shipped status.
- Some of it shipped (Pro) → the partially shipped status, until the units add up.
- Every shipment delivered (Pro) → the delivered status.

Status changes go through Commerce, so whatever emails you have on those statuses fire normally.
When the status does not move, Trackr still writes the carrier and tracking number to the order's
history rather than leaving the record silent.

## Item-level tracking (Pro)

A shipment can name line items and quantities instead of covering the whole order. Trackr counts
shippable units — digital goods are left out, or an order could never reach fully shipped — and
holds the order at partially shipped until they are all accounted for.

The rollup is recalculated from the shipment rows on every write, so deleting or editing a
shipment can never leave an order counting units that are not there.

## Console commands

| Command | What it does |
|---|---|
| `trackr/shipments/add <order> <tracking>` | Record tracking |
| `trackr/shipments/list` | Recent shipments |
| `trackr/shipments/show <order>` | Everything Trackr knows about one order |
| `trackr/shipments/set-status <tracking> <status>` | Move a shipment's delivery status |
| `trackr/shipments/recalculate [order]` | Rebuild the fulfilment rollup |
| `trackr/import/csv <file>` | Import a CSV (`--dry-run` to look first) |
| `trackr/import/watch` | Import the watched folder (Pro) |
| `trackr/import/sample` | Print a sample CSV |
| `trackr/log/prune` | Drop log rows past the retention window |

## Events

```php
use justinholtweb\trackr\services\Shipments;
use justinholtweb\trackr\events\ShipmentEvent;
use yii\base\Event;

Event::on(Shipments::class, Shipments::EVENT_AFTER_SAVE_SHIPMENT, function(ShipmentEvent $e) {
    // $e->shipment, $e->order, $e->isNew
});

Event::on(Shipments::class, Shipments::EVENT_AFTER_UPDATE_STATUS, function(ShipmentEvent $e) {
    // fires when a shipment reaches delivered, among others
});
```

## Permissions

- **View shipments** — the index, the order panel, shipment detail
- **Add, edit and delete tracking** — nested under it
- **Manage carriers**
- **View the activity log** (Pro)
  - **Prune and clear the activity log**

## License

Proprietary. Lite is free; Pro is a one-off $99 with a $49/year renewal.
