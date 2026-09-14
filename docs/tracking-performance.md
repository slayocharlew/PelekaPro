# Live-tracking performance

PelekaPro keeps MySQL authoritative while removing recurring Laravel traffic
from active customer tracking:

```text
Customer opens secure link
→ Laravel validates once and returns the authoritative snapshot
→ Laravel issues one delivery-scoped Firebase credential
→ Firebase /public_status reports waiting, active, and terminal state changes
→ Firebase /live reports the current accepted GPS point
→ no periodic Laravel snapshot polling
```

The browser may contact Laravel again only for explicit logout or recovery after
an invalid/missing Firebase configuration, authorization failure, or malformed
transport data. It does not poll merely to discover that the driver started.

## Write policy

The driver may advance one `/live` child approximately every five seconds. Each
accepted point replaces the previous point; no intermediate Firebase history is
created. Start and end evidence continues to be stored in MySQL. Delayed points
cannot move `/live` backwards. Firebase rules enforce delivery/session
ownership, active control, credential version, timestamps, sequence ordering,
payload validation, and deny driver writes to `/history`.

`/public_status` is also one fixed object. Laravel updates it only for delivery
lifecycle state needed by customer tracking; it does not grow with GPS traffic.

## Redis separation

Use separate Redis logical databases for unrelated workloads:

```text
DB 0 → default Redis connection
DB 1 → application cache and rate limiters
DB 2 → temporary Redis/Reverb rollback live locations
DB 3 → encrypted Laravel web-session data
```

This prevents high-volume rate-limit/session keys from sharing the live-location
namespace. MySQL is no longer used for general cache, rate-limit, or web-session
reads in the recommended environment.

## Pruning

`firebase-tracking:prune-history` remains as a bounded cleanup path for history
created by older app versions. New mobile and server location submissions do not
create history children. Production must continue running Laravel's scheduler
until legacy history has expired or been reviewed for removal.

## Safe local load test

The load harness uses the Firebase Realtime Database emulator only. It creates no
cloud data and requires no application or Firebase secret:

```bash
JAVA_HOME="/Applications/Android Studio.app/Contents/jbr/Contents/Home" \
PATH="$JAVA_HOME/bin:$PATH" \
npm run load:firebase
```

The default simulates 100 concurrent deliveries, one customer per delivery, and
12 five-second-equivalent overwrites of each delivery's single live child.
Writes are issued in bounded batches of 25 by default so the emulator models
independent devices without creating an unrealistic single-process burst. Set
`PELEKAPRO_LOAD_CONCURRENCY` between 1 and 200 when sizing a staging run.
Ramp progressively before targeting 5,000 concurrent deliveries:

```bash
PELEKAPRO_LOAD_DELIVERIES=500 \
PELEKAPRO_LOAD_VIEWERS_PER_DELIVERY=2 \
npm run load:firebase
```

Run 1,000 and 5,000 only on a machine sized for the emulator. Record latency,
errors, Firebase billing metrics, MySQL connections, Redis memory, and PHP worker
utilization in staging before promising that production capacity.
