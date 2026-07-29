<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#075e54">
        <meta name="color-scheme" content="light">
        <meta name="robots" content="noindex, nofollow, noarchive">
        <meta name="referrer" content="no-referrer">

        <title>Track your delivery · PelekaPro</title>

        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="icon" href="/icons/pelekapro-mark.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/icons/pelekapro-192.png">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="tracking-page">
        <div
            id="customer-tracking-app"
            class="tracking-shell"
            data-customer-tracking
            data-snapshot-url="{{ route('customer.tracking.session.show', absolute: false) }}"
            data-session-delete-url="{{ route('customer.tracking.session.destroy', absolute: false) }}"
            data-session-expires-at="{{ $trackingSessionExpiresAt }}"
        >
            <header class="tracking-header">
                <div class="tracking-container tracking-header__inner">
                    @include('tracking.partials.brand')

                    <div
                        id="tracking-connection"
                        class="connection-pill connection-pill--connecting"
                        role="status"
                        aria-live="polite"
                    >
                        <span class="connection-pill__dot" aria-hidden="true"></span>
                        <span id="tracking-connection-label">Connecting securely</span>
                    </div>
                </div>
            </header>

            <main class="tracking-main">
                <div class="tracking-container">
                    <div id="tracking-alert" class="tracking-alert" role="alert" hidden></div>

                    <section id="tracking-loading" class="tracking-loading" aria-live="polite">
                        <span class="tracking-spinner" aria-hidden="true"></span>
                        <div>
                            <strong>Loading your delivery</strong>
                            <p>Checking the latest secure tracking information…</p>
                        </div>
                    </section>

                    <div id="tracking-content" class="tracking-layout" hidden>
                        <aside class="tracking-summary" aria-labelledby="delivery-heading">
                            <div class="tracking-summary__eyebrow">Delivery</div>
                            <h1 id="delivery-heading">
                                <span class="sr-only">Tracking code </span>
                                <span id="tracking-code">—</span>
                            </h1>

                            <div class="tracking-status-card">
                                <div id="tracking-status-chip" class="status-chip status-chip--pending">
                                    <span class="status-chip__icon" aria-hidden="true"></span>
                                    <span id="tracking-status-label">Checking status</span>
                                </div>
                                <p id="tracking-status-message" role="status" aria-live="polite">
                                    We’re loading the latest delivery status.
                                </p>
                            </div>

                            <dl class="tracking-details">
                                <div>
                                    <dt>Last location update</dt>
                                    <dd>
                                        <time id="tracking-updated-time" datetime="">Not available</time>
                                    </dd>
                                </div>
                                <div>
                                    <dt>GPS accuracy</dt>
                                    <dd id="tracking-accuracy">—</dd>
                                </div>
                                <div>
                                    <dt>Current speed</dt>
                                    <dd id="tracking-speed">—</dd>
                                </div>
                                <div>
                                    <dt>Direction</dt>
                                    <dd id="tracking-heading">—</dd>
                                </div>
                            </dl>

                            <button id="end-tracking-session" class="tracking-end-button" type="button">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M6.4 5.35A9 9 0 1 0 17.6 5.35M12 3v9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                </svg>
                                End tracking session
                            </button>

                            <p class="tracking-privacy-note">
                                This page shows location only while this delivery has an active tracking session.
                            </p>
                        </aside>

                        <section class="tracking-map-card" aria-labelledby="map-heading">
                            <div class="tracking-map-card__header">
                                <div>
                                    <p class="tracking-map-card__eyebrow">Live position</p>
                                    <h2 id="map-heading">Delivery map</h2>
                                </div>
                                <span id="tracking-live-badge" class="live-badge" hidden>
                                    <span aria-hidden="true"></span>
                                    Live
                                </span>
                            </div>

                            <div id="tracking-map-frame" class="tracking-map-frame">
                                <div
                                    id="tracking-map"
                                    class="tracking-map"
                                    role="application"
                                    aria-label="Live delivery location map"
                                ></div>
                                <div id="tracking-map-placeholder" class="tracking-map-placeholder">
                                    <div class="tracking-map-placeholder__icon" aria-hidden="true">
                                        <svg viewBox="0 0 64 64">
                                            <path d="m8 15 15-7 18 7 15-7v41l-15 7-18-7-15 7V15Z" fill="none" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
                                            <path d="M23 8v41M41 15v41" fill="none" stroke="currentColor" stroke-width="3"/>
                                            <circle cx="33" cy="29" r="7" fill="currentColor"/>
                                        </svg>
                                    </div>
                                    <strong id="tracking-map-message-title">Waiting for a live location</strong>
                                    <p id="tracking-map-message">
                                        Your delivery has not started moving yet.
                                    </p>
                                </div>
                            </div>

                            <div class="tracking-map-footer">
                                <div>
                                    <span>Latitude</span>
                                    <strong id="tracking-latitude">—</strong>
                                </div>
                                <div>
                                    <span>Longitude</span>
                                    <strong id="tracking-longitude">—</strong>
                                </div>
                            </div>
                        </section>
                    </div>

                    <section id="tracking-ended" class="tracking-ended" hidden aria-live="polite">
                        <div class="tracking-ended__icon" aria-hidden="true">
                            <svg viewBox="0 0 48 48">
                                <path d="M24 43a19 19 0 1 0 0-38 19 19 0 0 0 0 38Z" fill="none" stroke="currentColor" stroke-width="3"/>
                                <path d="m15.5 24 5.5 5.5L33 17.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <h1 id="tracking-ended-title">Tracking session ended</h1>
                        <p id="tracking-ended-message">
                            This browser is no longer connected to the delivery tracking session.
                        </p>
                    </section>
                </div>
            </main>

            <footer class="tracking-footer">
                <div class="tracking-container">
                    <span>Secure delivery tracking by PelekaPro</span>
                    <span aria-hidden="true">·</span>
                    <span>Location is shared only during an active delivery</span>
                </div>
            </footer>
        </div>
    </body>
</html>
