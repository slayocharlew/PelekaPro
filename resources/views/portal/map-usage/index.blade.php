@extends('layouts.portal')

@section('title', 'Map usage')

@section('content')
    <div class="portal-page-heading">
        <h1>Map usage</h1>
        <form class="map-usage-month" action="{{ route('portal.map-usage.index') }}" method="GET">
            <div class="portal-field">
                <label for="map-usage-month">Month</label>
                <input id="map-usage-month" type="month" name="month" value="{{ $month->format('Y-m') }}" required>
                @error('month') <p class="portal-field__error">{{ $message }}</p> @enderror
            </div>
            <button class="portal-button portal-button--secondary" type="submit">View</button>
        </form>
    </div>

    <div class="map-usage-stats" aria-label="Website and rider Android map totals">
        <section class="portal-card map-usage-stat">
            <h2>Today</h2>
            <strong>{{ number_format($todayTotal) }}</strong>
            <p>{{ now()->timezone(\App\Services\MapUsageService::TIMEZONE)->format('j M Y') }}</p>
            <p>Website: {{ number_format($todayWebTotal) }} · Rider Android: {{ number_format($todayAndroidTotal) }}</p>
        </section>
        <section class="portal-card map-usage-stat">
            <h2>{{ $month->format('F Y') }}</h2>
            <strong>{{ number_format($monthlyTotal) }}</strong>
            <p>Map openings · Website: {{ number_format($monthlyWebTotal) }} · Rider Android: {{ number_format($monthlyAndroidTotal) }}</p>
        </section>
        <section class="portal-card map-usage-stat">
            <h2>Since counting began</h2>
            <strong>{{ number_format($allTimeTotal) }}</strong>
            <p>{{ $firstRecordedAt ? \Carbon\CarbonImmutable::parse($firstRecordedAt, 'UTC')->timezone(\App\Services\MapUsageService::TIMEZONE)->format('j M Y') : 'No map openings recorded yet' }}</p>
            <p>Website: {{ number_format($allTimeWebTotal) }} · Rider Android: {{ number_format($allTimeAndroidTotal) }}</p>
        </section>
    </div>

    <section class="portal-card map-usage-target" aria-labelledby="map-usage-target-heading">
        <div class="portal-card__header">
            <h2 id="map-usage-target-heading">Monthly website planning target</h2>
            <span>{{ number_format($monthlyWebTotal) }} / {{ number_format($target) }}</span>
        </div>
        <progress max="100" value="{{ $targetPercentage }}" aria-label="Recorded website map openings as a percentage of the planning target">{{ $targetPercentage }}%</progress>
        <p>{{ number_format(max(0, $target - $monthlyWebTotal)) }} website openings below your planning target. Rider Android openings are shown separately. This indicator does not stop maps or change Google quotas.</p>
        @if ($monthlyWebTotal >= (int) ceil($target * 0.8))
            <p role="status">{{ $monthlyWebTotal >= $target ? 'Planning target reached.' : 'Approaching your planning target.' }} Check Google Cloud usage and quotas.</p>
        @endif
    </section>

    <div class="map-usage-breakdown">
        <section class="portal-card" aria-labelledby="map-usage-screens-heading">
            <div class="portal-card__header"><h2 id="map-usage-screens-heading">By screen</h2></div>
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead><tr><th scope="col">Screen</th><th scope="col">Map openings</th></tr></thead>
                    <tbody>
                        @foreach ($surfaces as $surface)
                            <tr><td data-label="Screen">{{ $surface['label'] }}</td><td data-label="Map openings">{{ number_format($surface['total']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <section class="portal-card" aria-labelledby="map-usage-daily-heading">
            <div class="portal-card__header"><h2 id="map-usage-daily-heading">Daily openings</h2><span>Dar es Salaam time</span></div>
            <div class="portal-table-wrap map-usage-daily" tabindex="0" aria-label="Daily map openings; scroll for other dates">
                <table class="portal-table">
                    <thead><tr><th scope="col">Day</th><th scope="col">Website</th><th scope="col">Rider Android</th><th scope="col">Total</th></tr></thead>
                    <tbody>
                        @foreach ($daily as $day)
                            <tr>
                                <td data-label="Day">{{ $day['date']->format('j M') }}</td>
                                <td data-label="Website">{{ number_format($day['web_total']) }}</td>
                                <td data-label="Rider Android">{{ number_format($day['android_total']) }}</td>
                                <td data-label="Total">{{ number_format($day['total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <aside class="portal-card map-usage-notice" aria-labelledby="map-usage-notice-heading">
        <h2 id="map-usage-notice-heading">What is counted?</h2>
        <p>Website and rider Android maps are counted once when a map is created and reported. Refreshing or reopening a map counts again. Moving the rider, sending GPS updates, panning, zooming, and reconnecting without recreating the map do not.</p>
        <p>Android counts begin only after the rider app enables map-opening reporting. Android Maps SDK usage and website Dynamic Maps usage can have different billing categories; the website planning target is not a combined Google allowance.</p>
        <p>These are best-effort PelekaPro measurements, not Google's billing figures or a guarantee of free usage. Blocked requests or failed map rendering can cause differences. Earlier visits, route requests, and other Google services are not included.</p>
        <a class="portal-back-link" href="https://console.cloud.google.com/google/maps-apis/overview" target="_blank" rel="noopener noreferrer">Check official Google Maps usage ↗</a>
    </aside>
@endsection
