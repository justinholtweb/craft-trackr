---
title: Configuration
slug: configuration
order: 20
summary: Order statuses, the widget, the tracking page, the API token, carriers and permissions.
---

Everything lives at **Settings → Plugins → Trackr**. The settings screen is one page with
sections; this is what each of them decides.

## Order statuses

| Setting | What it does |
|---|---|
| **Shipped order status** | Where an order goes once everything on it has shipped. |
| **Partially shipped order status** | Where it sits while some of it is still to go. Pro. |
| **Delivered order status** | Where it goes once every shipment is marked delivered. Pro. |
| **Move the order when tracking is added** | Off records tracking without touching the status. |
| **Count units for partial shipments** | Holds an order at partially shipped until every shippable unit is covered. Pro. |
| **Write a note on the order history** | Records the carrier and number even when the status does not move. |

If you have not created those statuses, the settings screen offers to. Commerce order statuses
are project config, so creating one needs `allowAdminChanges` to be on — which it will not be on
production. Create them in your development environment and deploy the config.

**Move the order when tracking is added** is the switch to reach for if your order statuses are
driven by something else entirely. Trackr will still record tracking, still write the history
note, still show the widget — it just will not move the order.

## Carriers

| Setting | What it does |
|---|---|
| **Default carrier** | Pre-selected on the order screen. |
| **New shipments start as** | The delivery status a new shipment gets. |
| **Detect the carrier from the tracking number** | Used when a CSV row or an API call gives a number but no carrier. Pro. |
| **Preferred country** | Two-letter code. Carriers from this country sort to the top of the picker. |

Auto-detection only fires when **exactly one** carrier's pattern matches. Two matches means no
guess — putting a customer on the wrong carrier's website is worse than plain text.

The carrier list itself is at **Trackr → Carriers**, not here. See [Usage](usage#carriers).

## The widget

The widget's whole appearance is settings, with a live preview on the screen that updates as you
type.

| Setting | Default |
|---|---|
| **Heading** | `Track your order` |
| **Layout** | Cards, compact list, or a plain table |
| **Accent / Text / Muted / Background / Border / Button text** | Six colours |
| **Corner radius** | `6px` |
| **Show** delivery status, ship date, service, carrier logo, track button | All on |
| **Button label** | `Track shipment` |
| **Footer text** | Empty |

To replace the markup completely, point **`widgetTemplate`** at a site template. It gets the same
variables the built-in one does — see [Usage](usage#the-widget).

## The tracking page

| Setting | Default |
|---|---|
| **Tracking page enabled** | On |
| **URI** | `track` |
| **Template** | Trackr's own |
| **Require the email on the order** | On |
| **Max attempts / window** | 10 attempts per 600 seconds, per IP |

**Leave "require the email" on.** An order number alone is guessable — they are sequential on
most stores — and the email is the only thing standing between a stranger and someone else's
shipping information. The setting exists for stores whose order numbers are already random and
private, not as a convenience.

Failed lookups answer identically whatever went wrong. Telling somebody that an order number
exists but the email is wrong tells them the order number exists.

## Notifications (Pro)

| Setting | Default |
|---|---|
| **Email on shipment** | Off |
| **Email on delivery** | Off |
| **Subjects** | `Your order {orderNumber} has shipped` / `…has been delivered` |
| **Templates** | Trackr's own |

These are Trackr's own emails, separate from Commerce's status emails. If you already have a
Commerce email on your shipped status, you do not want these as well — pick one.

## Import

| Setting | What it does |
|---|---|
| **Order number source** | Which order field a CSV's order number matches — `auto`, the reference, or the number. |
| **Column aliases** | Extra header names to recognise, on top of the built-in loose matching. |
| **Watched folder** | Directory `trackr/import/watch` reads. Pro. |
| **Archive processed files** | Moves files aside once read, so cron does not re-import them. Pro. |

The watched folder accepts a Craft alias, so `@storage/trackr-inbox` works.

## API (Pro)

| Setting | Default |
|---|---|
| **API enabled** | Off |
| **Token** | Empty |

**With no token configured the endpoint rejects everything**, enabled or not. Generate a long
random token; it is compared with `hash_equals`, so length is the only defence that matters.

## Logging (Pro)

| Setting | Default |
|---|---|
| **Logging enabled** | On |
| **Store payloads** | On |
| **Retention** | 30 days |

Payloads are the raw CSV row or API body that produced a shipment, which is what you want when a
fulfilment service swears it sent something. They are also personal data with a shipping address
in them — set a retention window you can justify, and `php craft trackr/log/prune` on cron.

## Permissions

- **View shipments** — the index, the order panel, shipment detail
- **Add, edit and delete tracking** — nested under it
- **Manage carriers**
- **View the activity log** (Pro)

A warehouse user typically wants view plus add/edit, and nothing else.

## Config file

Everything above can be set in `config/trackr.php`, which overrides the CP and greys the fields
out:

```php
<?php

return [
    'shippedStatusHandle' => 'shipped',
    'trackingPageUri' => 'order-tracking',
    'apiEnabled' => true,
    'apiToken' => App::env('TRACKR_API_TOKEN'),
];
```

Put the API token in `.env` rather than in the file. It is a bearer token for an endpoint that
writes to orders.
