# Map usage dashboard

Sign in as an active super administrator and open **Map usage** in the portal,
or visit `/portal/map-usage`. Other roles cannot view these cross-business totals.

The dashboard shows today's total, a selected calendar month's total, totals
since counting started, daily counts, and counts by screen:

- Customer live tracking
- Customer delivery-request destination selection
- Business owner's shop settings
- Super administrator's business registration
- Rider Android app (after Flutter enables reporting)

Website and rider Android counts are shown separately for today, the selected
month, all time and each calendar day. The combined total is for activity
measurement, not for calculating a Google bill. The planning target remains
website-only.

Dates use Africa/Dar_es_Salaam. Events are stored in UTC. No earlier visits can
be recovered automatically; an empty dashboard means no openings have been
observed since this feature was installed.

## Counting and security

The shared Google Maps loader instruments the map constructor. It sends one
small same-origin POST when a map is created, not when a page loads without a
map, a marker moves, a GPS point arrives, a user pans/zooms, or Firebase/Reverb
reconnects. Returning to a page and creating another map counts another opening.
This adds no recurring request to the GPS or live-tracking flow.

Android uses the dedicated `POST /api/driver/map-usage` endpoint. It requires a
header-only Sanctum bearer token, an active driver account, a valid non-suspended
profile belonging to that same business, and an existing business. The server
sets business ownership, `driver_android`, provider and UTC receipt time.
Only `event_id` (a UUID) is read from the payload. Reports are limited to 30 per
minute per authenticated user; retries with the same UUID count only once.
Map viewing before start is measurable without activating GPS or tracking.

Each map-bearing page gets a short-lived, relative signed reporting URL.
The POST requires normal web CSRF protection plus the existing valid customer
cookie or active authorized portal user for that screen. Business ownership is
resolved server-side. Reports are IP-rate-limited to 30 per minute; UUIDs prevent
duplicate reports from increasing the count. No public tracking credential is
included in the reporting URL or body.

The new `map_usage_events` table stores only a generated event UUID, server-resolved
business ID (null for registration), screen, provider, and server receipt time.
It does not store IP addresses, user agents, coordinates, customer details, API
keys, or tracking tokens. A reporting failure never breaks the actual map.

## Planning target, not a bill

`PELEKAPRO_WEB_MAP_LOAD_TARGET=10000` is a configurable planning target for website
map openings. It is not a quota, an enforced spending cap, or a promise that your
bill is zero. Change it in your ignored local environment if desired and clear
configuration cache through the normal Laravel workflow.

Google remains authoritative for actual billing. Google bills map loads, not
marker movements, panning, or zooming; route calculations are separate.
See [Google's map-load counting rules](https://developers.google.com/maps/faq#usage_maploads)
and [official usage monitoring](https://developers.google.com/maps/documentation/javascript/usage-and-billing).

These are best-effort browser observations. Blocked reporting, expired customer
cookies/reporting URLs, network failures, deliberate fabricated client reports,
or failed Google rendering can make counts differ from Google. The dashboard
does not contact Google Cloud, activate paid billing, or change API quotas.

Google's current [SKU rules](https://developers.google.com/maps/billing-and-pricing/sku-details#maps-sdk-ess-sku)
distinguish ordinary Android maps without a Map ID (Maps SDK) from Android maps
with a Map ID (Dynamic Maps). The current [pricing table](https://developers.google.com/maps/billing-and-pricing/pricing)
lists unlimited free usage for Maps SDK. Routes and other APIs are separate.
Android openings are therefore not automatically part of a website allowance;
Map-ID-enabled Android maps can contribute to Dynamic Maps usage. Check the
actual mobile configuration and Google Cloud SKU reports before estimating
costs. PelekaPro does not infer that configuration or enforce Google's quotas.

The backend reporting endpoint is ready, but this Laravel task does not modify
the separate Flutter app. Android counts stay at zero until that app reports
its map creation. See [the Flutter integration contract and handoff](mobile-map-usage.md).

## Setup and tests

Run the new additive migration normally:

```sh
php artisan migrate
```

No existing business migration is modified, and no database reset is needed.

```sh
php artisan test --filter=MapUsage
npm run test:js
npm run build
```
