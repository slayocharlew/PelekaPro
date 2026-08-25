# Google Maps web configuration

PelekaPro uses the Google Maps JavaScript API for:

- customer live-delivery tracking;
- the customer-safe road route from the pickup point to the delivery destination;
- customer delivery-location selection; and
- business branch/shop location selection.

The customer tracking page also uses the Google Maps Routes library to compute
that pickup-to-destination driving route. It does not use the Places, Roads,
Geocoding, or Street View APIs.
GPS ingestion and the selected PelekaPro live-tracking transport remain separate
from Google Maps. Redis/Reverb rollback mode and Firebase Realtime Database mode
do not call the Routes API when a GPS point changes. The route is computed once
for each unique pickup/destination pair during a page visit; Firebase updates
move only the existing rider marker.

## Local configuration

Create a browser API key and a JavaScript Map ID in the PelekaPro Google Cloud
project. Keep their real values only in the ignored `.env` file:

```env
VITE_GOOGLE_MAPS_API_KEY=
VITE_GOOGLE_MAPS_MAP_ID=
```

The browser key and Map ID are visible to browsers by design. Protect the key
in Google Cloud with both of these restrictions:

1. Application restriction: Websites.
2. API restriction: allow only Maps JavaScript API and Routes API.

Both APIs must be enabled in the same Google Cloud project. The route remains a
visual aid: the delivery database and active tracking session still decide
whether location may be shown, and Firebase remains the live rider-position
transport.

Allow only the exact origins used by the project. Local development commonly
uses `http://localhost:*/*` and `http://127.0.0.1:*/*`. Add a temporary Mac LAN
origin only while testing from another device. Use the exact HTTPS application
origin before production deployment.

After changing a `VITE_` value, restart the Vite development server or rebuild
the frontend assets.

## Cost controls

In Google Cloud Console, set conservative daily quotas for both Maps JavaScript
API map loads and Routes API route computations. Configure the exact limits
from the current Google Maps Platform pricing and quota pages for the billing
account rather than relying on a hard-coded historical allowance.

Create billing alerts at USD $1, $5, and $10. Budget alerts do not stop usage;
the API quota is the hard protection against unexpected map-load charges.

Customer live tracking initializes Google Maps when the secure snapshot has at
least one authoritative route endpoint. It computes a road route only when both
pickup and destination coordinates exist. Reverb or Firebase marker updates
reuse that map and route; they do not recreate either one. Location-selection
pages initialize one map when opened.

If the Routes API is unavailable but Maps JavaScript loads, PelekaPro still
shows the authoritative pickup/destination pins and explains that the road route
is temporarily unavailable. If the API key, Map ID, network, referrer
authorization, or map quota is unavailable, PelekaPro shows a safe
map-unavailable message. Browser GPS remains available for location forms, and
delivery tracking continues to show customer-safe status and location details
without an interactive map.
