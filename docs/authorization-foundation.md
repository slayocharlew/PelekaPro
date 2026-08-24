# Authorization Foundation

This project now has route middleware aliases ready for the first API routes:

- `role`: restricts authenticated users by role name.
- `business.scope`: enforces that non-super-admin users only access their own `business_id`.
- `driver.delivery`: enforces that a driver can access only deliveries assigned to their user ID.
- `customer.tracking`: validates a short-lived encrypted customer-tracking cookie and
  resolves a minimal customer tracking principal for exactly one delivery.

Future controllers must enforce these rules:

- `business_owner` and `business_admin` can only access records scoped to their own business.
- `driver` can only access deliveries where `deliveries.assigned_driver_id` matches the authenticated user.
- A delivery's high-entropy `public_tracking_token` is accepted only by the initial
  `/track/{publicTrackingToken}` entry route. The response immediately creates an
  encrypted, HttpOnly tracking cookie and redirects to `/tracking/session`, so the
  raw token is not used by JavaScript or WebSocket channel authorization.
- Customer tracking sessions expire after 30 minutes by default. Configure this
  through `PELEKAPRO_CUSTOMER_TRACKING_SESSION_LIFETIME`.
- Customer private channels use a keyed, delivery-specific alias. Neither the
  delivery ID nor the public tracking token appears in the channel name.
- GPS tracking must start only after the driver taps Start Delivery.
- GPS tracking must stop when the delivery becomes `delivered`, `failed`, or `cancelled`.

The selected live-tracking driver controls temporary location transport. Redis
mode keeps permanent GPS history in MySQL and one temporary live point in Redis.
Firebase mode keeps only authoritative start/end GPS evidence in MySQL and
stores 30-day intermediate history plus the live point in Firebase Realtime
Database. In both modes, MySQL delivery status and the active tracking session
remain authoritative. See `docs/firebase-realtime-tracking.md`.
