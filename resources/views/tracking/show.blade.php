<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#ff6c37">
        <meta name="color-scheme" content="light">
        <meta name="robots" content="noindex, nofollow, noarchive">
        <meta name="referrer" content="no-referrer">

        <title>Track your delivery · PelekaPro</title>

        <link rel="manifest" href="/manifest.webmanifest">
        @include('partials.pelekapro-icons')

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="tracking-page tracking-page--live-map">
        <div
            id="customer-tracking-app"
            class="tracking-shell tracking-shell--live-map"
            data-customer-tracking
            data-snapshot-url="{{ route('customer.tracking.session.show', absolute: false) }}"
            data-session-delete-url="{{ route('customer.tracking.session.destroy', absolute: false) }}"
            data-session-expires-at="{{ $trackingSessionExpiresAt }}"
        >
            <main class="tracking-live-map-main">
                <div id="tracking-alert" class="tracking-alert tracking-alert--map-overlay" role="alert" hidden></div>

                <section id="tracking-loading" class="tracking-loading tracking-loading--map" aria-live="polite">
                    <span class="tracking-spinner" aria-hidden="true"></span>
                    <div>
                        <strong>Loading your delivery</strong>
                        <p>Connecting to the rider’s live location…</p>
                    </div>
                </section>

                <div id="tracking-content" class="tracking-map-experience" hidden>
                    <section class="tracking-map-stage" aria-labelledby="map-heading">
                        <h1 id="map-heading" class="sr-only">Live rider map</h1>

                        <div id="tracking-map-frame" class="tracking-map-frame tracking-map-frame--fullscreen">
                            <div
                                id="tracking-map"
                                class="tracking-map"
                                data-map-usage-url="{{ app(\App\Services\MapUsageService::class)->reportingUrl('customer_tracking') }}"
                                role="application"
                                aria-label="Live rider route from pickup to customer destination"
                            ></div>

                            <div id="tracking-map-placeholder" class="tracking-map-placeholder tracking-map-placeholder--fullscreen">
                                <div class="tracking-map-placeholder__icon" aria-hidden="true">
                                    <svg viewBox="0 0 64 64">
                                        <path d="m8 15 15-7 18 7 15-7v41l-15 7-18-7-15 7V15Z" fill="none" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
                                        <path d="M23 8v41M41 15v41" fill="none" stroke="currentColor" stroke-width="3"/>
                                        <circle cx="33" cy="29" r="7" fill="currentColor"/>
                                    </svg>
                                </div>
                                <strong id="tracking-map-message-title">Waiting for a live location</strong>
                                <p id="tracking-map-message">Your delivery has not started moving yet.</p>
                            </div>

                            <div class="tracking-map-topbar">
                                <div class="tracking-map-brand">@include('tracking.partials.brand')</div>
                                <div
                                    id="tracking-connection"
                                    class="connection-pill connection-pill--connecting"
                                    role="status"
                                    aria-live="polite"
                                >
                                    <span class="connection-pill__dot" aria-hidden="true"></span>
                                    <span id="tracking-connection-label">Connecting</span>
                                </div>
                            </div>

                            <div id="tracking-route-notice" class="tracking-route-notice tracking-route-notice--map" role="status" aria-live="polite" hidden></div>

                            <section class="tracking-driver-sheet" aria-labelledby="tracking-driver-name">
                                <div id="tracking-driver-avatar" class="tracking-driver-avatar" aria-hidden="true">D</div>

                                <div class="tracking-driver-identity">
                                    <span>Your driver</span>
                                    <h2 id="tracking-driver-name">Waiting for assignment</h2>
                                    <p id="tracking-driver-vehicle">Driver details will appear here.</p>
                                    <time id="tracking-updated-time" datetime="">Location not available</time>
                                </div>

                                <div class="tracking-driver-actions">
                                    <span id="tracking-live-badge" class="live-badge" hidden>
                                        <span aria-hidden="true"></span>
                                        Live
                                    </span>
                                    <button id="end-tracking-session" class="tracking-end-button tracking-end-button--compact" type="button">
                                        Close tracking
                                    </button>
                                </div>

                                <div class="tracking-driver-status">
                                    <div id="tracking-status-chip" class="status-chip status-chip--pending">
                                        <span class="status-chip__icon" aria-hidden="true"></span>
                                        <span id="tracking-status-label">Checking status</span>
                                    </div>
                                    <p id="tracking-status-message" role="status" aria-live="polite">
                                        We’re loading the latest delivery status.
                                    </p>
                                </div>
                            </section>
                        </div>
                    </section>
                </div>

                <section id="tracking-ended" class="tracking-ended tracking-ended--map-overlay" hidden aria-live="polite">
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
            </main>
        </div>
    </body>
</html>
