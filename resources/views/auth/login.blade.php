<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="light">
        <meta name="theme-color" content="#ff6c37">
        <meta name="robots" content="noindex, nofollow">
        <title>Sign in · PelekaPro</title>
        <link rel="icon" href="/icons/pelekapro-portal-favicon-32.png" type="image/png" sizes="32x32">
        <link rel="apple-touch-icon" href="/icons/pelekapro-portal-favicon-192.png" sizes="192x192">
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="portal-page portal-login-page">
        <main class="portal-login">
            <section class="portal-login__showcase" aria-labelledby="portal-welcome-heading">
                <div class="portal-login__showcase-orb portal-login__showcase-orb--one" aria-hidden="true"></div>
                <div class="portal-login__showcase-orb portal-login__showcase-orb--two" aria-hidden="true"></div>

                <div class="portal-login__showcase-brand">
                    <img src="/icons/pelekapro-portal-favicon-192.png" alt="" width="48" height="48">
                    <div>
                        <strong>Peleka<span>Pro</span></strong>
                        <small>Business delivery portal</small>
                    </div>
                </div>

                <div class="portal-login__illustration" aria-hidden="true">
                    <svg viewBox="0 0 720 500" role="presentation">
                        <defs>
                            <linearGradient id="portal-road-gradient" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0" stop-color="#ff8a5d" />
                                <stop offset="1" stop-color="#f15b28" />
                            </linearGradient>
                            <filter id="portal-illustration-shadow" x="-30%" y="-30%" width="160%" height="160%">
                                <feDropShadow dx="0" dy="18" stdDeviation="15" flood-color="#8c3214" flood-opacity=".18" />
                            </filter>
                        </defs>

                        <circle cx="360" cy="238" r="174" fill="#fff8f4" opacity=".92" />
                        <circle cx="568" cy="95" r="17" fill="#ff6c37" opacity=".5" />
                        <circle cx="112" cy="376" r="10" fill="#c74414" opacity=".35" />
                        <path d="M88 362C181 286 207 202 319 207c93 4 133 100 260 55" fill="none" stroke="url(#portal-road-gradient)" stroke-width="52" stroke-linecap="round" />
                        <path d="M88 362C181 286 207 202 319 207c93 4 133 100 260 55" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-dasharray="13 16" opacity=".9" />

                        <g transform="translate(503 166)" filter="url(#portal-illustration-shadow)">
                            <path d="M0 42C0 19 18 0 42 0s42 19 42 42c0 33-42 74-42 74S0 75 0 42Z" fill="#c74414" />
                            <circle cx="42" cy="41" r="16" fill="#fff" />
                        </g>

                        <g transform="translate(88 255)" filter="url(#portal-illustration-shadow)">
                            <rect x="0" y="0" width="122" height="84" rx="18" fill="#fff" />
                            <path d="M22 57h78M22 37h47" fill="none" stroke="#eadbd4" stroke-width="8" stroke-linecap="round" />
                            <circle cx="98" cy="24" r="12" fill="#ff6c37" />
                        </g>

                        <g transform="translate(246 154)" filter="url(#portal-illustration-shadow)">
                            <path d="M34 78h170c13 0 24 11 24 24v89H22v-89c0-13 5-24 12-24Z" fill="#fff" />
                            <path d="M60 37h91c17 0 32 9 41 23l29 47H42l18-70Z" fill="#ff6c37" />
                            <path d="M79 57h64c10 0 19 5 25 13l17 27H65l14-40Z" fill="#fff0ea" />
                            <rect x="0" y="112" width="244" height="75" rx="22" fill="#f15b28" />
                            <rect x="21" y="131" width="60" height="20" rx="10" fill="#fff0ea" />
                            <rect x="181" y="131" width="38" height="20" rx="10" fill="#fff0ea" />
                            <path d="M103 123h46v38h-46z" fill="#fff" opacity=".95" />
                            <path d="m126 132 13 8-13 8-13-8 13-8Zm-13 8v12l13 8 13-8v-12" fill="none" stroke="#ff6c37" stroke-width="3" stroke-linejoin="round" />
                            <circle cx="55" cy="190" r="29" fill="#30231f" />
                            <circle cx="55" cy="190" r="13" fill="#fff" />
                            <circle cx="188" cy="190" r="29" fill="#30231f" />
                            <circle cx="188" cy="190" r="13" fill="#fff" />
                        </g>

                        <g transform="translate(458 343)" filter="url(#portal-illustration-shadow)">
                            <rect width="145" height="93" rx="20" fill="#fff" />
                            <circle cx="35" cy="32" r="14" fill="#ff6c37" />
                            <path d="m28 32 5 5 10-11" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M61 29h58M25 62h94" fill="none" stroke="#eadbd4" stroke-width="8" stroke-linecap="round" />
                        </g>
                    </svg>
                </div>

                <div class="portal-login__showcase-copy">
                    <p class="portal-eyebrow">Built for local delivery teams</p>
                    <h1 id="portal-welcome-heading">Every delivery, under control.</h1>
                    <p>Assign drivers, follow progress and complete every order with confidence.</p>
                </div>
            </section>

            <section class="portal-login__panel" aria-labelledby="login-heading">
                <div class="portal-login__card">
                    <div class="portal-login__brand">
                        <img src="/icons/pelekapro-portal-favicon-192.png" alt="" width="60" height="60">
                        <div>
                            <strong>Peleka<span>Pro</span></strong>
                            <small>Business delivery portal</small>
                        </div>
                    </div>

                    <div class="portal-login__heading">
                        <p class="portal-eyebrow">Secure portal</p>
                        <h1 id="login-heading">Login to your account</h1>
                        <p class="portal-muted">Manage your delivery operations in one place.</p>
                    </div>

                    <form class="portal-form" method="POST" action="{{ route('login.store') }}" data-submitting-form>
                        @csrf
                        <div class="portal-field">
                            <label for="login">Email or phone</label>
                            <input
                                id="login"
                                name="login"
                                type="text"
                                value="{{ old('login') }}"
                                placeholder="owner@example.com or +255..."
                                autocomplete="username"
                                required
                                autofocus
                                aria-describedby="@error('login') login-error @enderror"
                            >
                            @error('login')
                                <p id="login-error" class="portal-field__error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="portal-field">
                            <label for="password">Password</label>
                            <input id="password" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" required>
                            @error('password')
                                <p class="portal-field__error">{{ $message }}</p>
                            @enderror
                        </div>

                        <button class="portal-button portal-button--primary portal-button--wide" type="submit" data-submit-label="Signing in…">
                            Login
                        </button>
                    </form>

                    <p class="portal-login__privacy">Portal access is limited to authorized PelekaPro business users.</p>
                </div>
            </section>
        </main>
    </body>
</html>
