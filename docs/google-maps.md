# Google Maps web configuration

PelekaPro uses the Google Maps JavaScript API for:

- customer live-delivery tracking;
- customer delivery-location selection; and
- business branch/shop location selection.

It does not use the Places, Routes, Roads, Geocoding, or Street View APIs.
GPS ingestion, Redis latest-location storage, and Reverb broadcasts remain
PelekaPro services and do not call Google Maps.

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
2. API restriction: Maps JavaScript API only.

Allow only the exact origins used by the project. Local development commonly
uses `http://localhost:*/*` and `http://127.0.0.1:*/*`. Add a temporary Mac LAN
origin only while testing from another device. Use the exact HTTPS application
origin before production deployment.

After changing a `VITE_` value, restart the Vite development server or rebuild
the frontend assets.

## Cost controls

In Google Cloud Console, set the Maps JavaScript API Dynamic Maps/map-load
daily quota to **250**. This caps normal usage at no more than 7,750 map loads
in a 31-day month, below the current monthly no-cost allowance of 10,000
Dynamic Maps loads.

Create billing alerts at USD $1, $5, and $10. Budget alerts do not stop usage;
the API quota is the hard protection against unexpected map-load charges.

Customer live tracking initializes Google Maps only after PelekaPro receives a
fresh, authoritative live location. Reverb marker updates reuse that map and do
not recreate it. Location-selection pages initialize one map when opened.

If the API key, Map ID, network, referrer authorization, or quota is unavailable,
PelekaPro shows a safe map-unavailable message. Browser GPS remains available
for location forms, and delivery tracking continues to show customer-safe
status and location details without an interactive map.
