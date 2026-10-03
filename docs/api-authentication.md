# API authentication

PelekaPro uses two separate Laravel authentication flows:

- The business portal continues to use the `web` guard and Laravel session cookies.
- Mobile and other API clients use Laravel Sanctum personal access tokens.

## Login

`POST /api/auth/login` accepts a password and exactly one login identifier. The
identifier may be sent as `phone`, `email`, or as `login` (where the server
matches the real `users.phone` or `users.email` column).

Token names and abilities are controlled by the server. Driver tokens are named
`flutter-driver` with the `driver-api` ability. Other API-user tokens are named
`pelekapro-api` with the `api` ability. A submitted `device_name` cannot override
these values.

The plain-text bearer token is returned only by a successful login response.
Sanctum stores only its SHA-256 hash. Clients must store the plain-text value
securely and send it only in the HTTP header:

```text
Authorization: Bearer <token>
```

Query-string tokens are not supported. A delivery's `public_tracking_token`
does not authenticate an API user.

## Token lifetime and revocation

Sanctum's default expiration setting is `43,200` minutes (30 days). New tokens
also receive a matching `expires_at` timestamp. The value may be configured
through `SANCTUM_EXPIRATION`; clients must log in again after expiry because
refresh tokens are not implemented.

- `POST /api/auth/logout` revokes only the bearer token used for the request.
- `POST /api/auth/logout-all` revokes every Sanctum token owned by the user.
- Inactive, suspended, or soft-deleted users cannot use old tokens.
- Drivers without an active profile, or with a suspended profile, cannot use
  old tokens.
- Local development-password rotation revokes all Sanctum tokens for the
  rotated accounts. Clients must treat subsequent `401` responses as requiring
  a fresh login, stop GPS, and discard old Firebase credentials too.

Revocation and account or driver-profile ineligibility take effect immediately,
even before the token's normal expiry.

## Request limits and browser origins

API traffic is limited before authentication to 300 requests/minute per IP.
Protected API requests also share a 120/minute per-user budget across tokens
and endpoints. Login allows five/minute per identifier+IP and twenty/minute
per IP across both web and API login. Existing narrower GPS and credential
limits remain unchanged. `429` includes `Retry-After`; clients must back off.
See [the mobile contract](mobile-driver-api.md#request-limits) for details.

`config/cors.php` allows no cross-origin browser API access by default.
`CORS_ALLOWED_ORIGINS` accepts only exact HTTP(S) origins, not wildcards,
credentials or URL paths. Native mobile/Postman traffic is unaffected. This
is a browser control, not a substitute for authentication or authorization.
Cookie-based portal/customer/broadcasting requests remain same-origin and
retain CSRF protection; API clients remain header-only bearer clients.

## Portal sessions

Portal login records an application-keyed fingerprint of the current password
hash in the server-side session. Protected portal routes and business-channel
authorization require that fingerprint to match. Password changes invalidate
old sessions. Sessions created before this hardening require one fresh login;
they are not automatically trusted by adding a missing fingerprint later.

The customer tracking guard is separate and unchanged. No Redis flush is used.
This check gates new HTTP/subscription requests; it does not forcibly disconnect
an already-authorized WebSocket. Likewise, Sanctum revocation alone does not
invalidate an already-issued Firebase lease; existing scoped expiry and
terminal-control revocation rules still apply. A confirmed compromise needs
explicit transport/session revocation, not only a password reset.

See [security operations](security-hardening.md) for the local rotation command
and remaining deployment checks.
