# Trackr — Craft CMS 5 Plugin

## Project Overview

Trackr is the tracking layer for Craft Commerce 5: tracking numbers get onto orders from anywhere,
and turn into something a customer can follow. Modelled on WooCommerce's *Advanced Shipment
Tracking*. Distributed as `justinholtweb/craft-trackr`. **Lite (free) + Pro ($99, $49/year renewal).**

## Why it exists

`[[project_craft_shipper]]` is *the ShipStation integration* — one label service, one protocol.
Trackr is carrier-agnostic: it does not care where the tracking came from, only that a customer
can follow it. The two are complementary, not competing, and Trackr never depends on Shipper.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step. One CP stylesheet (`web/assets/cp`, Craft's CSS variables), no JS beyond inline
  `{% js %}` blocks

## Architecture

### Namespace & package

- Namespace: `justinholtweb\trackr`
- Package: `justinholtweb/craft-trackr`
- Handle: `trackr`

### The two invariants

1. **`services\Shipments::record()` is the only place a shipment row is written.** The order
   screen, CSV import, the push API and every console command land there, so provider
   resolution, idempotency, the shipped-quantity maths and the order-status decision are made
   once and cannot disagree.
2. **`services\Providers::trackingUrl()` is the only place a tracking URL is built.** The CP
   link, the email widget, the tracking page and the Twig API all resolve through it, so a
   customer cannot be shown three different links for one parcel.

### Data model

- `{{%trackr_shipments}}` — unique on `(orderId, shipmentKey)`; that index *is* the idempotency
  guarantee. `shipmentKey` is `normalizedCarrier|lower(trackingNumber)`, falling back to a hash
  for a shipment with no tracking number at all.
- `{{%trackr_orderstate}}` — the per-order rollup. **Recalculated from the shipment rows on every
  write, never incremented** — an edited or deleted shipment must not leave an order counting
  units that are not there.
- `{{%trackr_log}}` — the activity log.

`providerName` is snapshotted onto each shipment row, so a shipment still reads correctly after
its carrier is renamed or dropped from settings.

### Providers

Built-ins live in `src/data/providers.php` (130 carriers) so a wrong URL can be corrected in a
release. Merchant changes are stored as **overrides** in settings rather than copies, so that
correction still reaches a merchant who only renamed the carrier. Custom carriers (Pro) are
separate entries and may deliberately shadow a built-in handle.

Auto-detection returns **null when more than one carrier's pattern matches**. Putting a customer
on the wrong carrier's website is worse than plain text, which is also why an unknown carrier is
recorded without a link rather than guessed at.

### Shipped-quantity rule

- Item-level shipment (Pro) → counts its items.
- Flagged partial (Pro) → counts nothing, so the order stays open.
- Anything else → counts whatever has not shipped yet, which is what a merchant means by pasting
  one tracking number onto an order.

Non-shippable line items are excluded from the total, or an order with a digital product could
never reach "fully shipped".

### Security notes

- The tracking page requires the order number **and** the email on the order, throttles failures
  per IP, and answers every failure identically — telling a stranger that an order number exists
  but the email is wrong tells them the order number exists.
- `Tracking::findOrder()` rejects `*`, `,` and `:`. Craft's query params treat those as syntax,
  and `reference('*')` would otherwise return an arbitrary order.
- The push API rejects everything when no token is configured. Auth is `hash_equals` against a
  bearer token, with `X-Trackr-Token` and a `token` query parameter as fallbacks for servers that
  strip the `Authorization` header.
- Uploaded CSVs are stashed under a Trackr-generated UUID; nothing a caller sends can walk out of
  the temp directory.
- **Tracking URLs are http(s) or nothing** (`helpers\Urls`, 5.0.1): checked in `Shipments::record()`
  and again wherever one is built or shown, because old rows and carrier templates may hold anything.
- **Anonymous budgets use `helpers\RateLimit`** (the family's): the tracking page's lookups and the
  API's failed sign-ins, keyed on the connecting address, never `getUserIP()`. The public page
  calls `findOrder($n, allowId: false)`; IDs are for the API, imports and the console.

## Traps found while building this

- **`Order::find()->number(null)` means "no filter", not "no match".** Passing a null through a
  Commerce query param returns an arbitrary order — every optional lookup has to guard first.
- **`??` binds looser than `===`**, so `if ($registry[$handle] ?? null === null)` is always
  truthy. Cost an always-failing override save until it was spotted.
- **Craft 5 addresses have no phone attribute** — it is a custom field that may not exist, so
  `getFieldValue('phone')` has to be wrapped.
- **Craft only writes an order history (and sends the status email) when the status actually
  changes.** A tracking note that must not masquerade as a status change goes straight to
  `OrderHistories::saveOrderHistory()`.
- **Commerce order statuses are project config**, so creating one needs `allowAdminChanges` and a
  `storeId`; `plugin/switch-edition` is likewise not a console command.
- **Console requests have no client**, so anything that may run from a command type-checks before
  reaching for `getUserIP()`.
- **`token` is Craft's own reserved query parameter.** Sending one on any request makes Craft
  throw `BadRequestHttpException: Invalid token` in `Application.php` before a controller runs —
  which is why the API's query-string fallback is `trackr_token`.
- **`savePluginSettings()` replaces the whole settings node in project config.** It writes
  `toArray(array_keys($settings))`, so saving one key silently resets every other setting to its
  default on the next request. `Providers::persist()` always sends the complete array.
- **Commerce marks `OrderHistory::userId` required**, so a note written from a CSV import, the
  push API or a console command fails validation. Trackr falls back to the order's customer, and
  skips validation rather than losing the note.
- **Project config strips empty arrays**, so `enabledProviders`, `customProviders` and
  `providerOverrides` simply do not appear in `project.yaml` until they hold something.

See `[[craft-plugin-gotchas]]` for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-trackr/tests/integration/checks.php
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-trackr/tests/integration/security.php  # 17: URLs, log perms, API shut-out, lookup throttle, CP styles
docker exec -w /sites/craft-trackr ddev-phpstan-runner-web bash -c 'vendor/bin/phpstan analyse --memory-limit=1G && vendor/bin/ecs check'
ddev exec bash -c 'find /var/www/craft-trackr/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

The suite switches to Pro for the bulk of the run, exercises Lite behaviour in its own section,
and restores the original edition, settings and every fixture in a `finally`.

**Harness note:** `craft-penny` registers an `Elements::EVENT_BEFORE_SAVE_ELEMENT` handler typed
`ModelEvent` while Craft passes an `ElementEvent`, so **every element save in the harness fatals**
while it is enabled. `checks.php` detaches that handler in-process. That is a bug in Penny, not
in Trackr — see `[[craft-penny-broken-save-handler]]`.

## Coding conventions

- `Craft::t('trackr', '…')` for user-facing strings; `src/translations/en/trackr.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — the order edit screen is one, so the panel posts with
  `Craft.sendActionRequest`
- Never mark plugin settings `required`
- The widget is table markup with inline styles, because its main home is an email client
- Logging and emailing never throw: neither may lose a tracking number that is already recorded
