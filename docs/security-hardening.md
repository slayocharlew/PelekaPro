# Security hardening — 3 October 2026

This change hardens the existing API and portal. It does not add mobile
endpoints, change successful driver payloads, modify business migrations, or
replace delivery ownership/status checks. See the
[mobile handoff](mobile-driver-api.md#security-update--3-october-2026).

## Defaults

- Keep `APP_DEBUG=false`, including for public ngrok development sessions.
- API IP budget: `PELEKAPRO_API_IP_LIMIT=300` per minute, before authentication.
- Authenticated API budget: `PELEKAPRO_API_USER_LIMIT=120` per user per minute.
- Portal budget: `PELEKAPRO_PORTAL_USER_LIMIT=120` per user per minute.
- Login IP budget: `PELEKAPRO_LOGIN_IP_LIMIT=20` per minute, shared across API
  and portal; the five/minute identifier+IP limit remains.
- Values are clamped to at least one. Tune with observed shared-network traffic;
  do not disable ownership checks or GPS-specific limits to increase capacity.
- Redis-backed cache shares limits across application workers. Keep it running;
  do not use per-process array cache for deployed rate limits. HTTP throttling
  mitigates abuse but does not replace edge-level DDoS protection.
- `CORS_ALLOWED_ORIGINS` is empty by default. Explicit exact browser origins
  only; no wildcard origins/patterns or cross-origin cookies. CORS does not
  prevent non-browser requests or replace Sanctum/policies.

## Local credential rotation

Do not put replacements in a seeder, README, command line, chat, or source file.
The command is restricted to `local`/`testing` environments:

```bash
php artisan security:rotate-development-passwords
php artisan security:rotate-development-passwords --apply
```

The first command is an audit only. `--apply` selects active, non-deleted users
whose password matches one of the known development defaults. It assigns each
a unique 24-character password, rotates remember tokens and revokes all their
Sanctum tokens under a database transaction. Other passwords are untouched.
This is not a general password-strength audit and does not remediate weak
passwords outside that explicit list or inactive accounts.

The replacements are written once to a UUID-named JSON file under
`storage/app/private/security/` (directory `0700`, file `0600`). This location
is ignored by Git and outside the public disk. Only the count and path are
printed. A failed handoff rolls back password changes and token revocations.
Move the credentials to a password manager, securely communicate them to the
affected account holders, and remove the local handoff afterward. Restrict
machine/backups access while it exists; it contains plaintext passwords.

Portal sessions must carry the password-bound proof created by a real login.
All pre-hardening portal sessions need one fresh login; password changes then
invalidate their older proofs on subsequent portal/channel-auth requests.
No global Redis clear or tracking-state deletion is needed. Login verifies
credentials and issues API tokens under the user row lock, preventing issuance
based on an old password racing this rotation.

## Dependency maintenance

Laravel, CommonMark and Flysystem receive targeted security updates in the
lockfile. Firebase's Firestore dependency still requests an older gRPC range,
so a documented `@grpc/grpc-js` override pins the patched `1.14.5` release.
Firebase remains on major 12. Review/remove the override when upstream uses a
patched compatible range. Do not use `npm audit fix --force` to downgrade it.

```bash
composer audit --locked
npm audit --omit=dev
npm audit
php artisan test
npm run test:js
npm run test:firebase-rules
npm run build
```

Development-tool advisories must be tracked separately from runtime dependencies.
A clean audit is not proof the application is unexploitable.

After compatible fixes on 3 October 2026, Composer and `npm audit --omit=dev`
report no known advisories. Full npm audit still flags 11 development-only
packages (7 high, 4 moderate) through Firebase CLI: unresolved roots are
`@opentelemetry/core`, `basic-ftp`, `braces` and `uuid`. npm proposes a breaking
Firebase CLI downgrade; it was not applied. Track upstream fixes separately,
keep development tools local and do not expose their services publicly.
Firebase CLI is `15.32.1`; Firebase app SDK remains `12.18.0`.
Node `26.5.0` also causes a pre-existing `superstatic` engine warning (supported
majors 20/22/24); standardize development tooling on a supported LTS separately.

## Deployment and incident checks still required

- Independently verify the deployed Firebase rules, API-key restrictions,
  service-account permissions, billing quotas and HTTPS settings; local tests
  cannot prove the cloud configuration matches source control.
- Keep Firebase leases scoped/short-lived and terminal revocation intact.
  Revoking Sanctum does not revoke an already-issued Firebase credential, and
  invalidating a portal session does not close an already-authorized socket.
  A confirmed compromise requires explicit transport revocation as well.
- Use HTTPS and secure, HttpOnly, SameSite portal/customer cookies in production.
  Do not force HTTPS-only cookies onto an HTTP localhost testing flow.
- Do not expose Redis, MySQL, Vite or unrestricted Reverb listeners publicly.
- Review logs/access, secret storage, least-privilege database credentials,
  backups, secret rotation and monitoring before a production launch.
- Never publish this development instance as if it were a hardened production
  deployment; arrange an independent security review before real customer scale.
