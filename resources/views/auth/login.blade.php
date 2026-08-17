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
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="portal-page portal-login-page">
        <main class="portal-login">
            <section class="portal-login__card" aria-labelledby="login-heading">
                <div class="portal-login__brand">
                    <span class="portal-brand__mark" aria-hidden="true">P</span>
                    <div>
                        <strong>PelekaPro</strong>
                        <span>Business delivery portal</span>
                    </div>
                </div>

                <div>
                    <p class="portal-eyebrow">Secure portal</p>
                    <h1 id="login-heading">Welcome back</h1>
                    <p class="portal-muted">Sign in with your registered email address or phone number.</p>
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
                        <input id="password" name="password" type="password" autocomplete="current-password" required>
                        @error('password')
                            <p class="portal-field__error">{{ $message }}</p>
                        @enderror
                    </div>

                    <button class="portal-button portal-button--primary portal-button--wide" type="submit" data-submit-label="Signing in…">
                        Sign in
                    </button>
                </form>

                <p class="portal-login__privacy">Portal access is limited to authorized PelekaPro business users.</p>
            </section>
        </main>
    </body>
</html>
