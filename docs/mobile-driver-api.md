# PelekaPro Mobile Driver API

This document is the integration contract between **PelekaPro Mobile** and the
existing Laravel backend. It is derived from the current routes, form requests,
resources, policies, services, and feature tests in this repository.

The Flutter application is a driver application. Owner and administrator APIs
are intentionally outside its responsibility.

## 1. Architecture and responsibilities

```text
Flutter driver UI
        ↓ HTTPS + Sanctum bearer token
Laravel API
        ↓
Authorization, workflow rules, transactions, and validation
        ↓
MySQL permanent history + Redis temporary latest location
        ↓
Laravel Reverb broadcasts accepted current state
```

### Flutter is responsible for

- collecting login credentials and sending them only to the login endpoint;
- keeping the returned bearer token in Android secure storage;
- rendering only the deliveries returned for the authenticated driver;
- submitting user-entered proof, PIN, collection, and failure information;
- starting GPS only after a successful start-delivery response;
- sending GPS samples approximately every five seconds;
- stopping GPS immediately after delivery, failure, cancellation, logout, an
  invalid session, or a server response saying tracking is no longer active;
- handling offline, timeout, validation, authorization, and throttling states;
- never treating a cached Flutter model as more authoritative than the server.

### Laravel is responsible for

- authenticating the user and deciding whether the account may use the API;
- resolving `business_id`, `driver_id`, delivery assignment, tracking session,
  expected payment, and authoritative payment method;
- enforcing business isolation and assigned-driver ownership;
- enforcing delivery status transitions and preventing duplicate transitions;
- persisting tracking history in MySQL;
- deciding whether a location becomes the latest Redis location;
- closing tracking sessions and removing Redis live state after terminal states;
- broadcasting only accepted latest locations and terminal delivery states.

Flutter must never connect directly to MySQL or Redis and must never submit
server-controlled ownership fields.

### Business branch pickup location

Pickup location is configured by the business, not by the driver. An authorized
business owner can complete the main shop location from the portal at
`/portal/settings`; the page uses the active business branch and an
Google Maps pin to save the written address and coordinates. The portal also
allows a super admin to register a business without a map pin so the owner can
complete it later.

When a delivery has an active `branch_id` belonging to the delivery's business,
Laravel resolves the pickup fields from that branch when creating or updating
the delivery:

```text
branch.name       → pickup.name
branch.phone      → pickup.phone
branch.address    → pickup.address
branch.latitude   → pickup.latitude
branch.longitude  → pickup.longitude
```

These values are server-authoritative. A mobile client must not attempt to
replace them with device input. If the branch has not yet been given a map pin,
the pickup coordinates may be `null`; the owner should complete Business
Settings before dispatching deliveries that require an exact pickup point. No
new mobile endpoint is required for this behavior—the existing driver delivery
resources already return the resolved `pickup` object.

## 2. Base URL and request conventions

The Flutter project reads its backend origin from the compile-time
`API_BASE_URL` value:

```bash
flutter run --dart-define=API_BASE_URL=http://MAC_LAN_IP:8000
```

The API prefix is `/api`, so a configured base URL of
`http://192.168.1.20:8000` produces endpoints such as:

```text
http://192.168.1.20:8000/api/auth/login
```

For a physical phone, `127.0.0.1` refers to the phone, not the Mac. During local
development, run Laravel on the LAN:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

Use HTTPS outside trusted local development.

### Default headers

Unauthenticated login request:

```http
Accept: application/json
Content-Type: application/json
```

Authenticated JSON request:

```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <sanctum-token>
```

Proof uploads use `multipart/form-data`. Let the Dart HTTP client generate the
multipart boundary; do not hardcode the `Content-Type` boundary.

Bearer tokens are accepted only from the `Authorization` header. Never put a
token in a URL, query string, log, exception report, screenshot, or analytics
event. A `public_tracking_token` is not an API credential.

## 3. Endpoint summary

All routes except login use both `auth:sanctum` and `active.api.user`.

| Method | Endpoint | Mobile responsibility | Backend responsibility |
|---|---|---|---|
| `POST` | `/api/auth/login` | Submit one identifier and password | Validate eligibility and issue one bearer token |
| `GET` | `/api/auth/me` | Restore/confirm signed-in driver | Return the current safe user profile |
| `POST` | `/api/auth/logout` | End this device session | Revoke only the current token |
| `POST` | `/api/auth/logout-all` | End every device session | Revoke all tokens for the user |
| `GET` | `/api/driver/deliveries` | Render assigned-delivery list | Return only deliveries assigned to `users.id` |
| `GET` | `/api/driver/deliveries/{delivery}` | Render detail and available failure reasons | Enforce assignment/business ownership |
| `POST` | `/api/driver/deliveries/{delivery}/start` | Start workflow, then GPS after success | Atomically start delivery and tracking session |
| `POST` | `/api/driver/deliveries/{delivery}/locations` | Submit device GPS samples | Persist history and conditionally update/broadcast live state |
| `POST` | `/api/driver/deliveries/{delivery}/deliver` | Submit delivery outcome, proof, and collection | Validate PIN/payment and atomically finish tracking |
| `POST` | `/api/driver/deliveries/{delivery}/fail` | Submit an allowed failure reason and optional proof | Atomically record failure and finish tracking |
| `GET` | `/api/deliveries/{delivery}/tracking-locations` | Optional authorized history/diagnostics | Return paginated MySQL history, never public live state |

The Flutter driver application must not use delivery CRUD, available-driver,
assignment, unassignment, or cancellation endpoints. Those are privileged
owner/admin operations.

## 4. Standard response and error handling

Most successful responses use:

```json
{
  "success": true,
  "message": "Human-readable result",
  "data": {}
}
```

Validation failures use:

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "field_name": ["Validation message"]
  }
}
```

Other failures normally use:

```json
{
  "success": false,
  "message": "Safe error message"
}
```

### Status-code behavior

| Status | Meaning in Flutter | Required handling |
|---|---|---|
| `200` | Successful read/action or duplicate GPS point | Consume response normally |
| `201` | New GPS point persisted | Consume returned location |
| `401` | Missing, invalid, expired, or revoked bearer token | Stop private work, clear secure token, show login |
| `403` | Authenticated but account/action/delivery is forbidden | Stop sensitive action and show a safe denial |
| `404` | Route-model delivery does not exist or is unavailable | Remove stale navigation and refresh assigned list |
| `409` | Delivery state transition or tracking state is no longer valid | Stop GPS when relevant and refetch delivery |
| `422` | Invalid credentials, fields, PIN, payment, timestamp, or rule | Render field errors without discarding valid form input |
| `429` | Rate limit exceeded | Back off; never retry in a tight loop |
| `500+` | Temporary server failure | Preserve safe local UI state and offer a controlled retry |

Do not automatically repeat start, deliver, or fail actions after an ambiguous
network timeout. Refetch the delivery first and inspect its authoritative
status. The exact same GPS payload may be retried because the backend detects
duplicates using session, latitude, longitude, and `recorded_at`.

## 5. Authentication API

### 5.1 Login

```http
POST /api/auth/login
```

Send `password` and exactly one of `login`, `phone`, or `email`.

```json
{
  "login": "+255700000000",
  "password": "driver-entered-password",
  "device_name": "Android phone"
}
```

| Field | Rules | Notes |
|---|---|---|
| `login` | Optional alternative, string, max 255 | Server treats a value containing `@` as email, otherwise phone |
| `phone` | Optional alternative, string, max 255 | Cannot be sent with `login` or `email` |
| `email` | Optional alternative, valid email, max 255 | Case-insensitive lookup |
| `password` | Required string | Never persist it |
| `device_name` | Optional string, max 255 | Currently cannot override the server-controlled token name |

Successful response:

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "access_token": "<plain-text-token-returned-once>",
    "token_type": "Bearer",
    "expires_at": "2026-09-05T10:00:00.000000Z",
    "user": {
      "id": 42,
      "business_id": 7,
      "branch_id": 3,
      "name": "Driver Name",
      "phone": "+255700000000",
      "email": "driver@example.test",
      "status": "active",
      "role": "driver",
      "driver_profile": {
        "id": 9,
        "is_available": true,
        "current_status": "available"
      }
    }
  }
}
```

The plain-text token is returned only once. Save it immediately in Android
secure storage. The default expiry is 43,200 minutes (30 days); there is no
refresh-token flow.

The endpoint can authenticate other eligible API roles, so PelekaPro Mobile
must require `data.user.role == "driver"`. If another role signs in, revoke the
new token and show that the driver application requires a driver account.

Login is limited to five attempts per minute per identifier/IP combination.
Inactive, suspended, soft-deleted, profileless, or suspended-profile drivers
receive the same generic invalid-credentials response.

### 5.2 Current driver

```http
GET /api/auth/me
```

Returns the safe `user` object directly under `data`:

```json
{
  "success": true,
  "data": {
    "id": 42,
    "business_id": 7,
    "branch_id": 3,
    "name": "Driver Name",
    "phone": "+255700000000",
    "email": "driver@example.test",
    "status": "active",
    "role": "driver",
    "driver_profile": {
      "id": 9,
      "is_available": true,
      "current_status": "available"
    }
  }
}
```

Use this endpoint on application startup after reading the secure token. A
driver can become ineligible before token expiry; old tokens are rejected
immediately when the user or driver profile becomes inactive.

### 5.3 Logout current device

```http
POST /api/auth/logout
```

No request body is required.

```json
{
  "success": true,
  "message": "Current token revoked."
}
```

After success, stop GPS, remove the token from secure storage, clear private
in-memory data, and return to login.

### 5.4 Logout every device

```http
POST /api/auth/logout-all
```

No request body is required.

```json
{
  "success": true,
  "message": "All tokens revoked."
}
```

This invalidates every Sanctum token owned by the driver.

## 6. Driver delivery resource

The assigned list and workflow endpoints return this customer/driver-safe
delivery shape. Decimal database casts are serialized as decimal strings; Dart
models should parse them deliberately instead of assuming JSON numbers.

```json
{
  "id": 101,
  "delivery_number": "PD-EXAMPLE-001",
  "tracking_code": "TRACK-EXAMPLE",
  "status": "assigned",
  "pickup": {
    "name": "Pickup contact",
    "phone": "+255700000001",
    "address": "Pickup address",
    "latitude": "-6.7924000",
    "longitude": "39.2083000"
  },
  "dropoff": {
    "name": "Drop-off contact",
    "phone": "+255700000002",
    "address": "Drop-off address",
    "latitude": "-6.8000000",
    "longitude": "39.2200000"
  },
  "customer": {
    "id": 15,
    "name": "Customer Name",
    "phone": "+255700000002"
  },
  "customer_address": {
    "label": "Home",
    "region": "Dar es Salaam",
    "district": "Kinondoni",
    "ward": "Example ward",
    "street": "Example street",
    "landmark": null,
    "building_instruction": null,
    "latitude": "-6.8000000",
    "longitude": "39.2200000"
  },
  "items": [
    {
      "id": 1,
      "delivery_id": 101,
      "item_name": "Parcel",
      "quantity": 1,
      "amount": "5000.00",
      "description": null,
      "created_at": "2026-08-06T08:00:00.000000Z",
      "updated_at": "2026-08-06T08:00:00.000000Z"
    }
  ],
  "payment": {
    "method": "cash_on_delivery",
    "amount_to_collect": "5000.00",
    "delivery_fee": "1000.00",
    "payment_record": {
      "payment_method": "cash",
      "expected_amount": "5000.00",
      "collected_amount": "0.00",
      "payment_status": "pending"
    }
  },
  "requirements": {
    "proof_supported": true,
    "available_proof_types": ["photo", "signature"]
  },
  "timestamps": {
    "assigned_at": "2026-08-06T08:00:00.000000Z",
    "started_at": null,
    "arrived_at": null,
    "delivered_at": null,
    "failed_at": null,
    "cancelled_at": null
  }
}
```

The detail response additionally includes active failure reasons:

```json
{
  "failure_reasons": [
    {"id": 1, "name": "Customer not reachable"}
  ]
}
```

The resource intentionally excludes the public tracking token,
proof filesystem paths, tracking-session internals, Redis keys, authentication
credentials, and reconciliation details.

The `pickup` object is the delivery's server-resolved business-branch pickup
point. Treat its coordinates as nullable and display the written address when a
pin has not yet been configured. Do not ask the driver to edit pickup
coordinates in the mobile app.

### Real delivery statuses

```text
created
location_pending
location_confirmed
assigned
accepted
on_the_way
arrived
delivered
failed
cancelled
```

Driver workflow rules currently used by the API:

```text
assigned | accepted
        → start
        → on_the_way

on_the_way | arrived
        → delivered | failed
```

`delivered`, `failed`, and `cancelled` are terminal.

## 7. Assigned-delivery APIs

### 7.1 List assigned deliveries

```http
GET /api/driver/deliveries
```

Returns all deliveries whose `assigned_driver_id` equals the authenticated
driver's `users.id`, ordered newest first.

```json
{
  "success": true,
  "message": "Assigned deliveries retrieved successfully",
  "data": [
    {"id": 101, "delivery_number": "PD-EXAMPLE-001", "status": "assigned"}
  ]
}
```

The real objects contain the full driver-delivery resource described above.
The endpoint is not currently paginated or filtered. Flutter may group the
returned records for presentation, but it must not manufacture statuses.

### 7.2 Delivery details

```http
GET /api/driver/deliveries/{delivery}
```

Only the assigned same-business driver may access it. Use this endpoint before
important transitions and when reconciling after an ambiguous network failure.
It is also the current source for active `failure_reasons`.

## 8. Start delivery

```http
POST /api/driver/deliveries/{delivery}/start
```

No body is required.

Success changes the status to `on_the_way`, sets `started_at`, and creates
exactly one active tracking session inside a database transaction.

```json
{
  "success": true,
  "message": "Delivery started successfully",
  "data": {
    "id": 101,
    "status": "on_the_way"
  }
}
```

The real `data` value is the full driver-delivery resource. Flutter must start
foreground GPS only after receiving this successful response. A repeated or
invalid start returns `409`; refetch instead of blindly retrying.

## 9. GPS location ingestion

```http
POST /api/driver/deliveries/{delivery}/locations
```

JSON request:

```json
{
  "latitude": -6.7924,
  "longitude": 39.2083,
  "accuracy": 8.5,
  "speed": 6.2,
  "heading": 135.0,
  "battery_level": 80,
  "recorded_at": "2026-08-06T08:15:30.000Z"
}
```

| Field | Required | Contract |
|---|---|---|
| `latitude` | Yes | Numeric, -90 through 90 |
| `longitude` | Yes | Numeric, -180 through 180 |
| `accuracy` | No | Non-negative metres |
| `speed` | No | Non-negative metres/second; preserve device `null` when unavailable |
| `heading` | No | Degrees, 0 through 360; preserve previous UI heading when `null` |
| `battery_level` | No | Integer percentage, 0 through 100 |
| `recorded_at` | Yes | Device sample time as UTC ISO-8601; not more than two minutes in the future |

Never submit `delivery_id`, `business_id`, `driver_id`, or
`tracking_session_id`. The route, authenticated user, delivery, and active
session determine ownership.

New location response (`201`):

```json
{
  "success": true,
  "message": "Location recorded successfully",
  "data": {
    "latitude": "-6.7924000",
    "longitude": "39.2083000",
    "accuracy": "8.50",
    "speed": "6.20",
    "heading": "135.00",
    "battery_level": 80,
    "recorded_at": "2026-08-06T08:15:30.000000Z"
  }
}
```

An exact duplicate returns `200`, `Location already recorded`, and the existing
safe location resource.

Rules enforced by Laravel:

- status must be `on_the_way` or `arrived`;
- `started_at` must exist;
- exactly one active tracking session must exist;
- session, assigned delivery, business, and authenticated driver must match;
- `recorded_at` cannot predate the active session;
- GPS is rejected before start and after delivery, failure, or cancellation;
- delayed older points remain in MySQL but do not replace newer Redis state;
- equal timestamps use the greater persisted location ID;
- only a point accepted as latest Redis state is broadcast;
- Redis/Reverb failure never rolls back committed MySQL history.

The rate limit is 12 requests per minute per driver/delivery, matching one
sample approximately every five seconds. On `429`, pause and back off. Do not
increase submission frequency. There is no altitude field.

## 10. Mark delivered

```http
POST /api/driver/deliveries/{delivery}/deliver
```

Use `multipart/form-data` when sending `proof_file`. JSON is acceptable when no
file is sent.

| Field | Required | Rules |
|---|---|---|
| `receiver_name` | No | String, max 255 |
| `receiver_phone` | No | String, max 255 |
| `proof_type` | With proof file | `photo` or `signature` |
| `proof_file` | No | JPEG, PNG, or WebP; maximum 5 MB |
| `proof_note` | No | String |
| `collected_amount` | Required when payment collection is required | Numeric, minimum 0 |
| `payment_method` | Prefer omission | If sent, must exactly match the server payment record |
| `payment_reference` | No | String, max 255 |
| `note` | No | String |
| `delivered_latitude` | No | Numeric, -90 through 90 |
| `delivered_longitude` | No | Numeric, -180 through 180 |

Do not send `expected_amount`. The server owns it. The safest Flutter behavior
is to display the returned payment record, submit only the actual
`collected_amount` and optional reference, and omit `payment_method`.

Payment behavior:

- cash/payment-required deliveries require a collected amount;
- a smaller collection remains `partial`;
- an equal or larger collection becomes `collected` without changing expected
  amount;
- prepaid, `none`, or zero-expected deliveries are `not_required` and cannot be
  converted into cash by the driver.

On success, Laravel atomically marks the delivery `delivered`, records proof
and payment, closes the tracking session, removes Redis live state after the
transaction, and broadcasts terminal state. Flutter must immediately stop GPS
and clear active-tracking UI.

## 11. Mark failed

```http
POST /api/driver/deliveries/{delivery}/fail
```

Use a failure reason ID returned by the delivery-detail endpoint.

| Field | Required | Rules |
|---|---|---|
| `failed_delivery_reason_id` | Yes | Integer ID of an active failure reason |
| `note` | No | String |
| `proof_type` | No | Only `photo` is accepted |
| `proof_file` | No | JPEG, PNG, or WebP; maximum 5 MB |
| `failed_latitude` | No | Numeric, -90 through 90 |
| `failed_longitude` | No | Numeric, -180 through 180 |

Example multipart fields without a file:

```text
failed_delivery_reason_id=3
note=Customer could not be reached
failed_latitude=-6.7924
failed_longitude=39.2083
```

On success, Laravel atomically marks the delivery `failed`, records the
failure, closes the tracking session, removes Redis live state, and broadcasts
terminal state. Flutter must stop GPS immediately.

## 12. Authorized tracking history

```http
GET /api/deliveries/{delivery}/tracking-locations?per_page=50
```

`per_page` is optional, from 1 through 100, and defaults to 50. Results are
ordered by `recorded_at` ascending.

```json
{
  "success": true,
  "message": "Tracking locations retrieved successfully",
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 50,
    "total": 1
  },
  "data": [
    {
      "latitude": "-6.7924000",
      "longitude": "39.2083000",
      "accuracy": "8.50",
      "speed": "6.20",
      "heading": "135.00",
      "battery_level": 80,
      "recorded_at": "2026-08-06T08:15:30.000000Z"
    }
  ]
}
```

This endpoint is historical evidence, not a current-live-location API. Do not
use the latest MySQL history point to claim that the driver is currently live.

## 13. Recommended Flutter feature mapping

| Flutter feature | API calls |
|---|---|
| Splash/session restore | `GET /api/auth/me` |
| Driver login | `POST /api/auth/login`, then validate role |
| Assigned deliveries | `GET /api/driver/deliveries` |
| Delivery details | `GET /api/driver/deliveries/{delivery}` |
| Pickup information | Read `pickup` from the assigned-delivery response; do not submit replacement branch coordinates |
| Start action | `POST .../{delivery}/start` |
| Foreground tracking | `POST .../{delivery}/locations` every ~5 seconds |
| Delivery completion form | `POST .../{delivery}/deliver` |
| Failure form | Detail failure reasons, then `POST .../{delivery}/fail` |
| Logout | `POST /api/auth/logout` |
| Logout every device | `POST /api/auth/logout-all` |

Recommended API-client behavior:

1. Keep the base URL in `AppConfig`, not in feature widgets.
2. Use one API client that adds `Accept: application/json` and bearer auth.
3. Keep transport DTOs separate from UI widgets.
4. Parse decimal strings and nullable timestamps defensively.
5. Convert backend field errors into form-field messages.
6. Clear secure authentication and stop GPS on `401`.
7. Refetch delivery state after transition timeouts or `409`.
8. Never log full requests for login, PIN, proof, location, or bearer tokens.

## 14. Current API gaps—do not invent client behavior

The following endpoints do **not** currently exist:

- accept assigned delivery;
- mark driver arrived;
- refresh Sanctum token;
- driver cancellation;
- batch/offline GPS upload;
- standalone failure-reason list;
- paginated or filtered assigned-delivery list;
- dedicated driver Reverb subscription/authentication.

The database and existing services recognize `accepted` and `arrived`, but the
driver API currently has no action that transitions into those statuses. If a
mobile UI requires either action, add a tested Laravel endpoint first rather
than faking the state in Flutter.

Business cancellation is owner/admin controlled. Until a dedicated driver
push mechanism is implemented, Flutter must reconcile delivery state whenever
the app resumes, when connectivity returns, and after location submissions are
rejected because tracking is no longer active.

## 15. Security checklist

- Store the Sanctum token only in Android secure storage.
- Never store the password.
- Never put bearer tokens in URLs.
- Never use a public customer tracking token for driver authentication.
- Never submit `business_id`, `driver_id`, or `tracking_session_id`.
- Never persist proof paths or Redis information in Flutter.
- Stop GPS whenever authoritative tracking becomes inactive.
- Do not retry state-changing actions blindly.
- Use HTTPS in release builds.
- Keep debug HTTP support out of the Android release manifest.

## 16. Updating this contract

When mobile development proves that an API is missing:

1. inspect the real migrations, models, policies, services, and tests;
2. define the backend request and response contract;
3. implement authorization, validation, transaction, and concurrency rules;
4. add Laravel feature tests;
5. update this document;
6. then implement the corresponding Flutter DTO, service, and UI.

Authentication does not replace authorization. Every future endpoint must
continue enforcing role, business isolation, assigned-driver ownership, active
user/profile state, and valid delivery transitions.
