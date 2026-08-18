<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow, noarchive">
        <meta name="referrer" content="no-referrer">
        <title>Details submitted · PelekaPro</title>
        @fonts
        @vite(['resources/css/app.css'])
    </head>
    <body class="delivery-request-page delivery-request-message-page">
        <main class="delivery-request-message" role="main">
            @include('tracking.partials.brand')
            <h1>Details submitted</h1>
            <p>The business will review your information and send a separate tracking link after creating the official delivery.</p>
        </main>
    </body>
</html>
