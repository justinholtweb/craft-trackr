---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and getting your first tracking number onto an order.
---

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later

## Install

```sh
composer require justinholtweb/craft-trackr
php craft plugin/install trackr
```

Or find **Trackr** in the Craft Plugin Store and install it from there.

## Nothing moves until you pick a status

Commerce has no shipped status of its own, so Trackr cannot guess where a shipped order should
go. Until you open **Settings → Plugins → Trackr** and choose one, tracking numbers are recorded
against the order and nothing else happens — no status change, no email.

If you have not built those statuses yet, the settings screen will create **Shipped**,
**Partially shipped** and **Delivered** for you.

## Your first tracking number

1. Go to **Settings → Plugins → Trackr** and set the **Shipped order status**.
2. Open any order in Commerce. Trackr's panel sits on the order edit screen.
3. Pick a carrier, paste the tracking number, save.
4. The order moves to your shipped status, and the carrier and number are written to the order's
   history whether it moved or not.

## Show it to the customer

One line in your Commerce order email template:

```twig
{{ craft.trackr.widget(order) }}
```

That is the whole integration. The widget is table markup with inline styles because it mostly
lives in email clients — see [Usage](usage#the-widget).

## Editions

Lite is free. Pro is a one-off $99 with a $49/year renewal, and adds the things you start needing
once fulfilment stops being one person pasting one number.

| | Lite | Pro |
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

## Existing orders

Installing Trackr does not invent history. Orders that shipped before you installed it have no
shipment rows, so they show as unshipped. If you have the tracking numbers in a spreadsheet,
[CSV import](usage#by-csv) will backfill them — and it shows you the whole plan before it writes
anything.
