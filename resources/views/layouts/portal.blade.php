<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="light">
        <meta name="theme-color" content="#ff6c37">
        <meta name="robots" content="noindex, nofollow">

        <title>@yield('title', 'Delivery portal') · PelekaPro</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="portal-page">
        <div class="portal-shell" data-portal>
            <header class="portal-header">
                <div class="portal-container portal-header__inner">
                    <a class="portal-brand" href="{{ route('portal.deliveries.index') }}" aria-label="PelekaPro delivery portal">
                        <span class="portal-brand__mark" aria-hidden="true">P</span>
                        <span>
                            <strong>PelekaPro</strong>
                            <small>Delivery control</small>
                        </span>
                    </a>

                    <button class="portal-nav-toggle" type="button" data-portal-nav-toggle aria-expanded="false" aria-controls="portal-navigation">
                        <span class="sr-only">Open navigation</span>
                        <span></span><span></span><span></span>
                    </button>

                    <nav id="portal-navigation" class="portal-nav" data-portal-nav aria-label="Portal navigation">
                        <a
                            href="{{ route('portal.deliveries.index') }}"
                            @class(['portal-nav__link', 'is-active' => request()->routeIs('portal.deliveries.*')])
                        >
                            Deliveries
                        </a>
                        <a
                            href="{{ route('portal.delivery-requests.index') }}"
                            @class(['portal-nav__link', 'is-active' => request()->routeIs('portal.delivery-requests.*')])
                        >
                            Requests
                        </a>
                        @if (auth('web')->user()->isSuperAdmin())
                            <a
                                href="{{ route('portal.businesses.index') }}"
                                @class(['portal-nav__link', 'is-active' => request()->routeIs('portal.businesses.*')])
                            >
                                Businesses
                            </a>
                        @endif
                    </nav>

                    <div class="portal-user">
                        <div class="portal-user__identity">
                            <strong>{{ auth('web')->user()->name }}</strong>
                            <span>
                                {{ str(auth('web')->user()->role?->name ?? 'user')->replace('_', ' ')->title() }}
                                @if (auth('web')->user()->business)
                                    · {{ auth('web')->user()->business->name }}
                                @endif
                            </span>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="portal-button portal-button--quiet portal-button--small" type="submit">Sign out</button>
                        </form>
                    </div>
                </div>
            </header>

            <main class="portal-main">
                <div class="portal-container">
                    @if (session('success'))
                        <div class="portal-alert portal-alert--success" role="status">{{ session('success') }}</div>
                    @endif

                    @if (session('error'))
                        <div class="portal-alert portal-alert--error" role="alert">{{ session('error') }}</div>
                    @endif

                    @if ($errors->has('delivery'))
                        <div class="portal-alert portal-alert--error" role="alert">{{ $errors->first('delivery') }}</div>
                    @endif

                    @if ($errors->has('delivery_request'))
                        <div class="portal-alert portal-alert--error" role="alert">{{ $errors->first('delivery_request') }}</div>
                    @endif

                    @yield('content')
                </div>
            </main>

            <footer class="portal-footer">
                <div class="portal-container">PelekaPro · Secure delivery operations</div>
            </footer>
        </div>
    </body>
</html>
