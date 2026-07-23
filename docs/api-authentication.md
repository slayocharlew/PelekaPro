# API authentication

PelekaPro uses two separate Laravel authentication flows:

- The business portal continues to use the `web` guard and Laravel session cookies.
- Mobile and other API clients use Laravel Sanctum personal access tokens.

## Login

`POST /api/auth/login` accepts a password and exactly one login identifier. The
identifier may be sent as `phone`, `email`, or as `login` (where the server
matches the real `users.phone` or `users.email` column). An optional
`device_name` labels the token; it defaults to `pelekapro-client`.

The plain-text bearer token is returned only by a successful login response.
Sanctum stores only its SHA-256 hash. Clients must store the plain-text value
securely and send it only in the HTTP header:

```text
Authorization: Bearer <token>
```

Query-string tokens are not supported. A delivery's `public_tracking_token`
does not authenticate an API user.

## Token lifetime and revocation

Sanctum's current expiration setting is `null`, and tokens are created without
an `expires_at` value. Tokens therefore have no automatic expiration and remain
valid until they are revoked or the owning account is no longer eligible for
API access.

- `POST /api/auth/logout` revokes only the bearer token used for the request.
- `POST /api/auth/logout-all` revokes every Sanctum token owned by the user.
- Inactive, suspended, or soft-deleted users cannot use old tokens.
- Drivers without an active profile, or with a suspended profile, cannot use
  old tokens.

Refresh tokens are not implemented. A later expiration policy should be added
through Sanctum configuration together with a deliberate mobile re-login
experience.
