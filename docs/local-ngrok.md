# Local development through ngrok

PelekaPro serves built JavaScript, CSS and fonts for every non-loopback hostname,
including ngrok and Mac LAN addresses. The URLs use the incoming application
origin, so an HTTPS ngrok page does not import an HTTP Vite development server.
This still works when Vite recreates `public/hot` or the ngrok hostname changes.

On `http://localhost`, `http://127.0.0.1` and `http://[::1]`, hot reload continues
to work while Vite is running. HTTPS loopback pages use hot reload only when the
Vite hot URL is also HTTPS. Production and other non-development environments
always use built assets.

## Start a public development session

Build the frontend first:

```bash
npm run build
```

Keep Laravel running in one terminal:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Keep ngrok running in another terminal:

```bash
ngrok http 8000
```

Use the HTTPS forwarding URL shown by the ngrok agent. Local Vite development
may run alongside this session; it cannot switch public pages back to port 5173.
Rebuild with `npm run build` after frontend changes that should appear through
ngrok. Do not delete `public/hot`, open Vite publicly, or add wildcard CORS origins.
Laravel's existing trusted-loopback proxy configuration preserves HTTPS form
actions and asset URLs; it does not require trusting arbitrary proxies.

## What this fix does not change

- An offline ngrok endpoint still requires restarting the ngrok agent.
- If the public hostname changes, update the mobile API base URL and Google Maps
  browser-key restrictions separately. Do not broaden those restrictions to `*`.
- Live WebSocket delivery still requires its configured Reverb connection (when
  using Reverb), and live Firebase tracking still requires Firebase connectivity.
- Built assets must exist. A missing build is not allowed to fall back to a
  development server that public visitors cannot reach.

The asset policy is implemented by `App\Support\RequestAwareVite`, registered
for Laravel's Vite service in `AppServiceProvider`. It covers `@vite`, `@fonts`
and `Vite::asset()` without changing the hot-file path or application `.env`.
