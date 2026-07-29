<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#075e54">
        <meta name="robots" content="noindex, nofollow, noarchive">
        <meta name="referrer" content="no-referrer">

        <title>Tracking session unavailable · PelekaPro</title>

        <link rel="icon" href="/icons/pelekapro-mark.svg" type="image/svg+xml">

        @fonts
        @vite('resources/css/app.css')
    </head>
    <body class="tracking-page">
        <main class="tracking-invalid">
            <div class="tracking-invalid__card">
                @include('tracking.partials.brand')

                <div class="tracking-invalid__icon" aria-hidden="true">
                    <svg viewBox="0 0 48 48">
                        <path d="M24 43a19 19 0 1 0 0-38 19 19 0 0 0 0 38Z" fill="none" stroke="currentColor" stroke-width="3"/>
                        <path d="M24 14v12M24 33.5v.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                    </svg>
                </div>

                <h1>Tracking session unavailable</h1>
                <p>
                    This tracking link is invalid or the secure session has expired.
                    Please use the original tracking link again.
                </p>
            </div>
        </main>
    </body>
</html>
