# Rider Android map-opening reporting

## Backend contract

```http
POST /api/driver/map-usage
Accept: application/json
Content-Type: application/json
Authorization: Bearer <current Sanctum token>
```

```json
{
  "event_id": "8d582e70-cd67-4a32-9bcf-2a228c88f4ed"
}
```

The UUID above is a safe example, not a credential. Generate a fresh UUID for
each actual Google map instance. If retrying a failed report for that same
instance, reuse its UUID. Do not reuse the example UUID for real reports.

`API_BASE_URL` is the existing backend origin; append `/api/driver/map-usage`
using the mobile project's existing API client. Do not change the configured
origin or duplicate an existing `/api` prefix.

- **204:** recorded, or this UUID was already recorded. The response has no JSON
  body. Do not parse it as the usual `success/data` envelope.
- **401:** missing, expired, invalid or revoked bearer token. Normal website
  sessions, public tracking tokens and query-string tokens cannot authenticate.
- **403:** wrong role, blocked user/profile, missing/deleted business, or a
  profile belonging to another business.
- **422:** missing or malformed event UUID; `success: false` with a generic
  `Invalid map usage report.` message.
- **429:** more than 30 reports in a minute for that user. Respect `Retry-After`.
- **503:** usage recording unavailable; the actual map/delivery must keep working.

Laravel supplies business ownership, screen, Google provider and UTC receipt
time. Send no coordinates, IDs, payment details, map key, tracking token,
password, socket metadata or device identifiers. A map may be viewed before
delivery starts; reporting its opening does not start a GPS/tracking session.
The endpoint does not change deliveries, location history, Firebase or Redis.

## Flutter handoff prompt (Android Studio)

Implement only rider map-opening reporting against the existing Laravel API.
Inspect the Flutter project's Google Maps widget, API client, Sanctum session
handling, dependencies and tests first. Preserve all current mobile work.

1. On actual Google map creation, send a non-blocking authenticated
   `POST /api/driver/map-usage` with only a new UUID `event_id`.
2. Keep this UUID/report flag in memory for that map instance. Widget rebuilds,
   GPS updates, Firebase listeners, camera animation, marker movement, pan,
   zoom and ordinary connection restoration must not generate new reports.
3. Creating a genuinely new map instance counts again. A limited retry for the
   same instance uses the same UUID; do not create a persistent retry queue.
4. Use the existing API base URL and bearer-token client. Recognize 204 as
   success without expecting JSON. Never log tokens or report payloads.
5. Do not await telemetry before showing the map, starting/completing delivery,
   or processing GPS. A failure must not show a blocking spinner, break map
   rendering, restart tracking, or cause repeated page/widget reloads.
6. Do not send ownership values or GPS data. Do not add any reporting to the
   five-second GPS timer or direct Firebase GPS writes.
7. Use an existing UUID helper if available, otherwise a small secure Dart
   implementation. Avoid new packages solely for this one report if possible.
8. Add tests for one map creation/one report, repeated widget builds, repeated
   GPS updates/no extra report, retry/same UUID, new instance/new UUID, 204
   handling and non-blocking API failures. Run format, analyze and tests.
9. Report whether the current Android Google Maps widget uses a Map ID. Do not
   change the key or Map ID, billing, GPS cadence, tracking authority or app UI.

Do not commit or push unless separately authorized. Do not modify the Laravel
repository from the mobile task.

## Dashboard and billing

After Flutter integration, an active super administrator sees reported rider
openings in `/portal/map-usage`, separately from website openings. Old openings
cannot be recovered. This is best-effort application telemetry, not Google's
official usage or billing figures.

Google's [usage rules](https://developers.google.com/maps/billing-and-pricing/sku-details#maps-sdk-ess-sku)
distinguish Android Maps SDK maps without a Map ID from Dynamic Maps with one.
The [pricing table](https://developers.google.com/maps/billing-and-pricing/pricing)
currently lists Maps SDK as unlimited free usage. Check the actual map setup
and Cloud SKU reports; routes, Street View and other services are separate.
