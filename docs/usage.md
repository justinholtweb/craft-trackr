---
title: Usage
slug: usage
order: 30
summary: The four ways tracking gets in, the widget, the tracking page, carriers, Twig and console.
---

## Four ways in, one way through

Tracking arrives by hand, by CSV, over the API or from a console command. All four land in the
same method, so the same tracking number sent twice updates one shipment rather than creating a
second — which matters, because fulfilment services retry and merchants re-upload spreadsheets.

A shipment is identified by its carrier and its tracking number together. Send the same pair
again and you have edited the shipment you already had.

### By hand

Trackr adds a panel to Commerce's own order edit screen: carrier, tracking number, ship date,
delivery status, note. On Pro the panel also lets you say which line items and how many of each
are in the box.

### By CSV

**Trackr → Import.** Upload the file. Trackr reads the header row, works out which column is
which, resolves every order and every carrier, and shows you exactly what it would do — new
shipments, updates to existing ones, and rows it could not resolve. Nothing is written until you
confirm.

Headers are matched loosely, so a supplier's `Tracking No`, `tracking_number` and
`TRACKING-NUMBER` are the same column. The only required column is the order number.

```csv
Order Number,Tracking Number,Carrier,Ship Date,Service,Status
CT-1042,1Z999AA10123456784,UPS,2026-08-14,Ground,in_transit
CT-1043,9400111899223197428490,USPS,2026-08-14,Priority Mail,delivered
```

`php craft trackr/import/sample` prints a file with every column Trackr understands.

On Pro, `php craft trackr/import/watch` imports everything in the watched folder and moves the
files aside. Point a supplier's SFTP drop at it and put the command on cron.

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

`201` for a new shipment, `200` when it updated one that already existed — so a service that
retries on timeout cannot double-ship an order.

| Endpoint | What it does |
|---|---|
| `POST /actions/trackr/api/shipments` | Record or update a shipment |
| `GET /actions/trackr/api/shipments?order_number=` | Read tracking back |
| `POST /actions/trackr/api/status` | Move a shipment's delivery status |
| `GET /actions/trackr/api/carriers` | The carrier handles Trackr understands |

Apache commonly strips the `Authorization` header, so `X-Trackr-Token` and a `trackr_token` query
parameter are accepted too. Not `token` — that is Craft's own preview-token parameter, and Craft
rejects the request before Trackr sees it.

### From the command line

```sh
php craft trackr/shipments/add CT-1042 1Z999AA10123456784 --carrier=ups
php craft trackr/shipments/show CT-1042
php craft trackr/shipments/list
php craft trackr/shipments/set-status 1Z999AA10123456784 delivered
php craft trackr/shipments/recalculate CT-1042
php craft trackr/import/csv tracking.csv --dry-run
php craft trackr/import/watch          # Pro
php craft trackr/import/sample
php craft trackr/log/prune             # Pro
```

`recalculate` rebuilds the fulfilment rollup from the shipment rows. You should never need it —
the rollup is recalculated on every write — but it is there for after a direct database edit.

## The widget

```twig
{{ craft.trackr.widget(order) }}
```

One line in your Commerce order email template, or on an account page, or both. It renders as
table markup with inline styles, because its main home is an email client that strips `<style>`
blocks and will not load an external sheet.

Colours, layout and what it shows are all [settings](configuration#the-widget), with a live
preview on the settings screen.

To replace the markup entirely, point `widgetTemplate` at a site template. It receives the same
variables the built-in one does: `order`, `shipments`, `settings` and `progress`.

If you would rather build your own from scratch:

```twig
{% for shipment in craft.trackr.shipments(order) %}
    <li>
        {{ shipment.getProviderLabel() }} — {{ shipment.trackingNumber }}
        {% if shipment.getTrackingUrl() %}
            <a href="{{ shipment.getTrackingUrl() }}">Track</a>
        {% endif %}
        <span>{{ shipment.getStatusLabel() }}</span>
    </li>
{% endfor %}
```

## The tracking page

Trackr serves a customer-facing page at `/track`. A customer enters their order number and the
email address on the order; a link from the widget carries the order's UID and skips the form.

No account is needed, which is the point — most Commerce stores take guest orders, and a guest
who cannot log in still wants to know where their parcel is.

Failed lookups are throttled per IP and every failure answers identically. See
[Configuration](configuration#the-tracking-page) for why you should leave the email requirement
on.

`{{ craft.trackr.trackingPageUrl(order) }}` gives you the direct link for an order.

## Carriers

**Trackr → Carriers** lists the 130 built in, across North America, Europe, Asia-Pacific, South
America, Africa and the Middle East, plus LTL freight, print-on-demand and the aggregators.

Switch off the ones you never use so the picker stays short. On Pro you can also:

- **Rename** one — your customers may know DHL eCommerce by a different name.
- **Point it at a different URL** — some carriers run a different tracking host per region.
- **Give it a logo** for the widget.
- **Add your own**, including a white-labelled *Our delivery van* with no URL at all, which
  records a number without pretending it links anywhere.

Merchant changes are stored as overrides rather than copies, so if a built-in carrier's URL is
wrong and gets corrected in a Trackr release, the correction still reaches you when all you did
was rename it.

Tracking URLs are templates. `{tracking_number}` is the one every carrier uses; where a carrier
needs more, `{postal_code}`, `{phone}`, `{country}` and `{ship_date}` are available. A template,
like any tracking URL, has to be `http://` or `https://`. Trackr won't save or link anything else.

A carrier Trackr does not recognise is still recorded — the number just shows as plain text.
A link that 404s is worse than no link.

## Item-level tracking (Pro)

A shipment can name line items and quantities instead of covering the whole order. Trackr counts
shippable units — digital goods are excluded, or an order with a download in it could never reach
fully shipped — and holds the order at partially shipped until every unit is accounted for.

Three rules decide what a shipment counts for:

- An **item-level shipment** counts the items it names.
- A shipment **flagged partial** counts nothing, so the order stays open.
- **Anything else** counts whatever has not shipped yet — which is what a merchant means by
  pasting one tracking number onto an order.

The rollup is recalculated from the shipment rows on every write, never incremented, so deleting
or editing a shipment can never leave an order counting units that are not there.

## Twig API

```twig
{{ craft.trackr.widget(order) }}          {# the tracking widget #}
{{ craft.trackr.shipments(order) }}       {# every shipment, newest first #}
{{ craft.trackr.latest(order) }}          {# the most recent one, or null #}
{{ craft.trackr.hasShipped(order) }}
{{ craft.trackr.isFullyShipped(order) }}
{{ craft.trackr.isDelivered(order) }}
{{ craft.trackr.progress(order) }}        {# {shipped, total, remaining, percent} #}
{{ craft.trackr.trackingPageUrl(order) }}
{{ craft.trackr.trackingUrl('ups', '1Z999AA10123456784') }}
{{ craft.trackr.providers() }}
{{ craft.trackr.detect('1Z999AA10123456784').name }}   {# Pro #}
```

`trackingUrl()` is the only place a tracking URL is ever built — the CP link, the widget, the
tracking page and this all resolve through it, so a customer cannot be shown three different
links for one parcel.

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

Both fire wherever the shipment came from — order screen, CSV, API or console — because all four
go through the same method.
