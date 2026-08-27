@php
    $portalPartial = request()->header('X-PelekaPro-Partial') === '1';
    $portalNavigationSection = match (true) {
        request()->routeIs('portal.deliveries.*') => 'deliveries',
        request()->routeIs('portal.delivery-requests.*') => 'delivery-requests',
        request()->routeIs('portal.drivers.*') => 'drivers',
        request()->routeIs('portal.businesses.*') => 'businesses',
        request()->routeIs('portal.settings.*') => 'settings',
        default => '',
    };
@endphp

@unless ($portalPartial)
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
        <link rel="icon" href="/icons/pelekapro-portal-favicon-32.png" type="image/png" sizes="32x32">
        <link rel="apple-touch-icon" href="/icons/pelekapro-portal-favicon-192.png" sizes="192x192">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="portal-page">
        <div class="portal-shell" data-portal data-portal-base="{{ url('/portal') }}">
            <div class="portal-navigation-progress" data-portal-progress hidden aria-hidden="true"></div>
            <p class="sr-only" data-portal-navigation-status role="status" aria-live="polite"></p>
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
                            data-portal-nav-section="deliveries"
                            @class(['portal-nav__link', 'is-active' => request()->routeIs('portal.deliveries.*')])
                        >
                            Deliveries
                        </a>
                        <a
                            href="{{ route('portal.delivery-requests.index') }}"
                            data-portal-nav-section="delivery-requests"
                            @class(['portal-nav__link', 'is-active' => request()->routeIs('portal.delivery-requests.*')])
                        >
                            Requests
                        </a>
                        @if (auth('web')->user()->isBusinessOwner() || auth('web')->user()->isBusinessAdmin())
                            <a
                                href="{{ route('portal.drivers.index') }}"
                                data-portal-nav-section="drivers"
                                @class(['portal-nav__link', 'is-active' => request()->routeIs('portal.drivers.*')])
                            >
                                Drivers
                            </a>
                        @endif
                        @if (auth('web')->user()->isSuperAdmin())
                            <a
                                href="{{ route('portal.businesses.index') }}"
                                data-portal-nav-section="businesses"
                                @class(['portal-nav__link', 'is-active' => request()->routeIs('portal.businesses.*')])
                            >
                                Businesses
                            </a>
                        @endif
                        @if (auth('web')->user()->isBusinessOwner())
                            <a
                                href="{{ route('portal.settings.edit') }}"
                                data-portal-nav-section="settings"
                                @class(['portal-nav__link', 'is-active' => request()->routeIs('portal.settings.*')])
                            >
                                Settings
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
@else
    <template data-portal-title>@yield('title', 'Delivery portal') · PelekaPro</template>
    <template data-portal-navigation-state>{{ $portalNavigationSection }}</template>
@endunless

            <main class="portal-main" data-portal-main tabindex="-1">
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

@unless ($portalPartial)
            <footer class="portal-footer">
                <div class="portal-container">PelekaPro · Secure delivery operations</div>
            </footer>
        </div>
    </body>
</html>
@endunless
