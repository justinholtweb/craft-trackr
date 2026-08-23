---
title: FAQ
slug: faq
order: 50
summary: Common questions about shipment tracking in Craft Commerce.
---

## Does Trackr connect to my label service?

No, and that is the point. Trackr is carrier-agnostic: it does not care where a tracking number
came from, only that a customer can follow it. Numbers arrive by hand, by CSV, over the API or
from a console command, and 130 carriers' tracking URLs are already built in.

If you want a ShipStation *integration* — labels, rates, one protocol — that is a different
plugin. Trackr sits downstream of whatever you already use, including several at once.

## Does it fetch live tracking status from the carrier?

No. Trackr records the status you tell it — from the order screen, a CSV column, or the API — and
shows it. It does not poll carrier APIs.

That is a deliberate line. Live polling means an API key per carrier, a rate limit per carrier,
and a support burden every time one of them changes. Your label service already knows the status;
push it to Trackr and it will show it.

## Will it change my order numbers?

No. Trackr never writes to `order.number` or `order.reference`. It records shipments against an
order and, if you let it, moves the order's status.

## Do I have to change my email templates?

One line: `{{ craft.trackr.widget(order) }}`. The widget is inline-styled table markup so it
survives email clients, and its colours and layout are settings with a live preview.

## Can one order have several tracking numbers?

Yes, on both editions. That is normal — a large order goes in more than one box. On Pro you can
also say which line items and quantities are in each one.

## What happens if the same tracking number is sent twice?

It updates the shipment you already have rather than creating a second. Carrier plus tracking
number identifies a shipment, which is what makes retrying fulfilment services and re-uploaded
spreadsheets safe.

## Can customers track without an account?

Yes. The tracking page at `/track` takes an order number and the email address on the order — no
login. The link in the widget carries a token and skips the form entirely.

## Is the tracking page safe to leave public?

Yes, with the email requirement left on. Failed lookups are throttled per IP and every failure
answers identically, so it cannot be used to work out which order numbers exist. Order numbers
alone are guessable on most stores; the email is the thing actually protecting the address.

## Does Trackr create the order statuses I need?

It will, from the settings screen — **Shipped**, **Partially shipped** and **Delivered**, which
Commerce does not ship with. Because order statuses are project config, do that in development
and deploy the config; production has `allowAdminChanges` off.

## Can I record a delivery my own van made?

Yes, on Pro. Add a custom carrier with no URL at all — a white-labelled *Our delivery van* — and
Trackr records the reference without pretending it links anywhere.

## What if Trackr does not know my carrier?

The number is still recorded; it shows as plain text with no link. On Pro you can add the carrier
yourself with its URL template. A link that 404s is worse than no link, which is why an unknown
carrier is never guessed at.

## Why did auto-detection not identify my carrier?

Because more than one carrier's pattern matched. Trackr only guesses when exactly one does — the
number shapes overlap more than you would hope, and sending a customer to the wrong carrier's
website is a worse outcome than plain text.

## Which Craft and Commerce versions are supported?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.

## Is Trackr free?

Lite is, and it is not a trial. Tracking on the order screen, 130 carriers, multiple numbers per
order, the widget, the customer tracking page, CSV import and the full Twig API, for nothing. Pro
is a one-off $99 with a $49/year renewal.

## What is the difference between Lite and Pro?

Lite covers a merchant fulfilling orders themselves. Pro covers fulfilment that has become
somebody else's job: item-level tracking and partial shipments, carrier auto-detection, custom
and white-labelled carriers, the push API for 3PLs and ERPs, watched-folder CSV import on cron,
delivery status with notification emails, and the activity log with payloads. See
[Installation](installation#editions) for the full table.

## What happens when the renewal lapses?

Pro keeps working. Renewals buy updates, the way every Craft plugin licence does — your tracking
numbers carry on being recorded either way.

## Does it work with Craft Commerce's own shipping methods?

Yes, and it does not interfere with them. Commerce decides what shipping was bought; Trackr
records what actually went out the door. They are separate facts and Trackr does not assume they
match.
