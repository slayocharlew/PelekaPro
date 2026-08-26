# Firebase Realtime Database tracking

PelekaPro supports a controlled live-tracking cutover with:

```text
MySQL
→ authoritative delivery status, tracking-session lifecycle, and start/end GPS evidence

Firebase Realtime Database
→ one replaceable current live point and one customer-safe status object

Redis + Reverb
→ retained as the rollback transport while PELEKAPRO_LIVE_TRACKING_DRIVER=redis
```

Set `PELEKAPRO_LIVE_TRACKING_DRIVER=firebase` only after the Firebase project,
Realtime Database, Authentication, rules, web client, Android client, and service
account have all been verified. The default remains `redis` so an incomplete
Firebase setup cannot break existing deliveries.

## Data ownership

MySQL remains authoritative for whether tracking is active. Starting a delivery
creates one active MySQL tracking session and a `point_type=start` location.
Delivered, failed, or cancelled transitions revoke Firebase writes and remove
the live point before the terminal MySQL transaction commits. A final
`point_type=end` location is saved from the submitted terminal GPS point or the
last valid Firebase point when one exists. A terminal transition is not blocked
only because no final point is available.

Firebase stores data under opaque HMAC aliases:

```text
delivery_tracking/{deliveryAlias}/control
delivery_tracking/{deliveryAlias}/live
delivery_tracking/{deliveryAlias}/public_status
```

No raw delivery ID, business ID, driver ID, public tracking token, customer
information, payment data, PIN, proof path, Sanctum token, or Redis key appears
in a client-visible Firebase path or location payload.

## Cloud setup

Authenticate the Firebase CLI interactively:

```bash
npx firebase login
npx firebase projects:list
```

Select the existing PelekaPro Google Cloud project; do not create a duplicate
project merely to obtain Realtime Database. In Firebase Console:

1. Enable Firebase Authentication. Custom-token sign-in uses the Admin SDK; no
   customer password provider is required.
2. Create Realtime Database in the `europe-west1` region.
3. Register the web application used by the customer tracker.
4. Register Android application `tz.co.pelekapro.mobile`.
5. Create a least-privilege server service account able to create Firebase
   custom tokens and access this Realtime Database.

Keep the service-account JSON outside the repository. Point the ignored `.env`
to that absolute local path:

```env
PELEKAPRO_LIVE_TRACKING_DRIVER=firebase
FIREBASE_CREDENTIALS=/absolute/private/path/pelekapro-firebase-service-account.json
FIREBASE_DATABASE_URL=https://YOUR_DATABASE.europe-west1.firebasedatabase.app

VITE_FIREBASE_API_KEY=browser-client-key
VITE_FIREBASE_AUTH_DOMAIN=YOUR_PROJECT.firebaseapp.com
VITE_FIREBASE_DATABASE_URL=https://YOUR_DATABASE.europe-west1.firebasedatabase.app
VITE_FIREBASE_PROJECT_ID=YOUR_PROJECT
VITE_FIREBASE_APP_ID=web-app-id
```

The web API key is a client identifier, not a server secret, but it must be
restricted to the intended web origins and Firebase APIs. The service-account
private key is a server secret and must never use a `VITE_` variable.

Deploy only the reviewed database rules:

```bash
npx firebase use YOUR_PROJECT_ID
npm run test:firebase-rules
npx firebase deploy --only database
```

The rules deny all access by default. Driver custom tokens can advance only the
single `live` child for their active delivery/session and only in
timestamp/sequence order. They cannot create Firebase history children.
Customer custom tokens are read-only and scoped to one opaque delivery alias.
Control state is never customer-readable.

Customer credentials may be issued before the driver starts. In that state the
customer may read only `public_status`; `live` remains empty and Firebase rules
deny that read until authoritative control becomes active. The browser then
receives start, live, and terminal changes directly from Firebase without
periodically polling Laravel.

## Credential leases

Laravel issues 30-minute custom-token leases through:

```text
POST /api/driver/deliveries/{delivery}/tracking-credentials
POST /tracking/firebase-credentials
```

The driver endpoint still requires Sanctum, active-user middleware, assigned
driver ownership, same-business ownership, active driver profile, an active
delivery status, and exactly one active tracking session. The customer endpoint
still requires the encrypted, HttpOnly customer tracking cookie. Tokens contain
only scoped aliases, a role, an expiry, and revocation/version claims.

## Legacy cleanup and retry

New tracking sessions do not create Firebase location history. The legacy
history pruning command remains temporarily available to delete history left by
older application versions. Laravel schedules:

```text
firebase-tracking:prune-history
firebase-tracking:retry-outbox
```

Production must run Laravel's scheduler. The terminal outbox is recorded in the
same MySQL transaction as the terminal delivery state. A Firebase outage after
commit therefore cannot undo delivery completion, and the safe terminal status
is retried without restoring live writes.

`/live` may advance approximately every five seconds, and every accepted point
replaces the previous value. `public_status` is a fixed lifecycle object updated
for waiting, active, and terminal states; it is not an append-only GPS log. See
`docs/tracking-performance.md` for emulator load testing.

## Local verification

Firebase database rules run against the local emulator and never touch a cloud
project:

```bash
JAVA_HOME="/Applications/Android Studio.app/Contents/jbr/Contents/Home" \
PATH="$JAVA_HOME/bin:$PATH" \
npm run test:firebase-rules
```

Run the Laravel and frontend regressions separately:

```bash
php artisan test
npm run test:js
npm run build
```

Do not delete the Redis/Reverb implementation during the initial cutover. To
roll back location transport for new deliveries, restore
`PELEKAPRO_LIVE_TRACKING_DRIVER=redis`, clear Laravel configuration, and use a
mobile build without Firebase client defines. A delivery that already started
keeps the transport recorded by its MySQL start point: active Firebase sessions
continue receiving scoped credentials and still revoke Firebase on terminal
state, while active Redis sessions remain on Redis/Reverb. This prevents a
feature-flag change from bypassing terminal tracking cleanup.
