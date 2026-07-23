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

Revocation and account or driver-profile ineligibility take effect immediately,
even before the token's normal expiry.
