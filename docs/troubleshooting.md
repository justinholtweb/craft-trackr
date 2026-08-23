---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: Orders that never moved, tracking numbers with no link, CSV rows that would not resolve, and API calls that come back 401.
---

## The order did not move to shipped

In order of how often it turns out to be each:

1. **No shipped status is set.** [Configuration](configuration#order-statuses) — Trackr will not
   guess.
2. **"Move the order when tracking is added" is off.** Tracking is being recorded; the status is
   deliberately not being touched.
3. **The order is not fully shipped.** On Pro with unit counting on, an item-level shipment that
   covers 2 of 3 units holds the order at partially shipped. Open the order panel — it shows what
   is still outstanding.
4. **Every line item is non-shippable.** Digital products are excluded from the total. An order
   made entirely of downloads has nothing to ship, so it never reaches fully shipped.

`php craft trackr/shipments/show <order>` prints everything Trackr knows about one order,
including the rollup it calculated and why.

## No status email went out

Craft only writes an order history — and only fires the status email — when the status actually
changes. If the order was already on your shipped status, adding tracking will not send anything.

That is also why the history note is a separate setting. Trackr writes the carrier and tracking
number to the order's history directly, so the record is not silent even when the status did not
move.

## The tracking number shows as plain text with no link

The carrier is not one Trackr recognises, or the carrier field was left empty and auto-detection
declined to guess.

Auto-detection returns nothing when **more than one** carrier's pattern matches — several
carriers use the same number shapes, and putting a customer on the wrong carrier's website is
worse than plain text. Set the carrier explicitly on the CSV row or the API call.

If the carrier is right but the link is wrong, check **Trackr → Carriers** for an override
somebody added. Test the URL from that screen before trusting it.

## The settings screen says it saved, but nothing changed

Check for `config/trackr.php`. A setting present in the config file overrides the CP and the field
is shown greyed out — Craft will still report a successful save of the settings that are not
overridden.

## Trackr will not create the order statuses

Commerce order statuses are project config, so creating one requires `allowAdminChanges`, which is
off on production by definition. Create the statuses in development and deploy the project config.

## CSV rows would not resolve

The preview names the reason per row. The common ones:

- **Order not found.** Check **Order number source** in [Configuration](configuration#import). A
  CSV of Commerce *references* against a store matching on *number* resolves nothing.
- **The order number column was not recognised.** Header matching is loose but not clairvoyant.
  Add the supplier's exact header under **Column aliases**.
- **Carrier not recognised.** The row still imports — the number is recorded without a link.

`php craft trackr/import/csv <file> --dry-run` gives you the same plan on the command line.

## The watched folder is not importing

- It is a **Pro** feature. On Lite the command runs and reports that.
- Check the path resolves. Aliases like `@storage/trackr-inbox` are expanded; a relative path is
  not.
- Check the web user can read the directory *and* write to it — files are moved aside once read,
  and a folder it cannot write to will re-import everything on every run.

## The API returns 401

- **No token is configured.** With no token the endpoint rejects everything, whether or not the
  API is switched on. That is deliberate.
- **The `Authorization` header is being stripped.** Apache does this routinely. Send
  `X-Trackr-Token` or a `trackr_token` query parameter instead.
- **You used `token` as the query parameter.** That is Craft's own preview-token parameter — Craft
  throws `BadRequestHttpException: Invalid token` before the plugin ever runs. It must be
  `trackr_token`.

## The API returns 200 when I expected 201

It updated a shipment that already existed. Carrier plus tracking number identifies a shipment, so
sending the same pair again is an edit. This is what stops a retrying fulfilment service from
double-shipping an order.

## The widget looks wrong in an email

Email clients strip `<style>` blocks and will not load an external stylesheet, which is why the
widget is table markup with inline styles. If you have pointed `widgetTemplate` at your own
template, it needs to follow the same rule.

Outlook in particular ignores `border-radius` — the corners will be square there and there is
nothing to be done about it.

## A shipment was deleted but the order still says fully shipped

It should not. The rollup is recalculated from the shipment rows on every write, never
incremented. If you see this, something wrote to the database directly — run
`php craft trackr/shipments/recalculate <order>` and please report it.

## Where to look next

**Trackr → Log** (Pro) records every write with the payload that produced it — the raw CSV row or
API body — which settles most arguments about what a fulfilment service actually sent.

Trackr's own logging never throws. Neither logging nor emailing may lose a tracking number that
is already recorded, so a failure there is swallowed rather than rolled back into the save.
