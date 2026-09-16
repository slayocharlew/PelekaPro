<?php

namespace App\Services;

use App\Models\MapUsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

class MapUsageService
{
    public const DRIVER_ANDROID_SURFACE = 'driver_android';

    public const WEB_SURFACES = [
        'customer_tracking' => 'Customer tracking',
        'customer_delivery_request' => 'Customer delivery requests',
        'shop_location' => 'Shop settings',
        'business_onboarding' => 'Business registration',
    ];

    public const SURFACES = [
        ...self::WEB_SURFACES,
        self::DRIVER_ANDROID_SURFACE => 'Rider Android app',
    ];

    public const TIMEZONE = 'Africa/Dar_es_Salaam';

    public function reportingUrl(string $surface): string
    {
        if (! array_key_exists($surface, self::WEB_SURFACES)) {
            throw new \InvalidArgumentException('Unknown map usage screen.');
        }

        $routeName = match ($surface) {
            'customer_tracking' => 'customer.tracking.map-usage',
            'customer_delivery_request' => 'customer.delivery-request.map-usage',
            default => 'portal.map-usage.store',
        };
        $parameters = str_starts_with($surface, 'customer_') ? [] : ['surface' => $surface];

        return URL::temporarySignedRoute(
            $routeName,
            now()->addMinutes(30),
            $parameters,
            absolute: false,
        );
    }

    public function record(string $eventId, string $surface, ?int $businessId): void
    {
        if (! array_key_exists($surface, self::SURFACES)) {
            throw new \InvalidArgumentException('Unknown map usage screen.');
        }

        // One write per map creation, never per GPS update. UUID uniqueness makes retries idempotent.
        MapUsageEvent::query()->insertOrIgnore([
            'event_id' => strtolower($eventId),
            'business_id' => $businessId,
            'surface' => $surface,
            'provider' => 'google',
            'loaded_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(CarbonImmutable $month): array
    {
        $month = $month->setTimezone(self::TIMEZONE)->startOfMonth();
        $end = $month->addMonth();
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $monthly = MapUsageEvent::query()
            ->where('loaded_at', '>=', $month->utc()->format('Y-m-d H:i:s'))
            ->where('loaded_at', '<', $end->utc()->format('Y-m-d H:i:s'));

        $bySurface = (clone $monthly)->selectRaw('surface, COUNT(*) AS total')
            ->groupBy('surface')->pluck('total', 'surface');

        // UTC storage with EAT calendar-day reporting, without one SQL query for every day.
        $driver = $monthly->getConnection()->getDriverName();
        $dayExpression = $driver === 'sqlite'
            ? "date(loaded_at, '+3 hours')"
            : 'DATE(DATE_ADD(loaded_at, INTERVAL 3 HOUR))';
        $byDay = (clone $monthly)->selectRaw(
            $dayExpression.' AS day, COUNT(*) AS total, SUM(CASE WHEN surface = ? THEN 1 ELSE 0 END) AS android_total',
            [self::DRIVER_ANDROID_SURFACE],
        )->groupBy('day')->get()->keyBy('day');

        $daily = [];
        for ($day = $month; $day->lessThan($end); $day = $day->addDay()) {
            $counts = $byDay->get($day->toDateString());
            $total = (int) ($counts?->total ?? 0);
            $androidTotal = (int) ($counts?->android_total ?? 0);
            $daily[] = [
                'date' => $day,
                'total' => $total,
                'web_total' => $total - $androidTotal,
                'android_total' => $androidTotal,
            ];
        }

        $target = max(1, (int) config('pelekapro.map_usage.monthly_web_load_target', 10000));
        $total = (int) $bySurface->sum();
        $webTotal = (int) $bySurface->only(array_keys(self::WEB_SURFACES))->sum();
        $androidTotal = (int) $bySurface->get(self::DRIVER_ANDROID_SURFACE, 0);
        $totalsExpression = 'COUNT(*) AS total, SUM(CASE WHEN surface = ? THEN 1 ELSE 0 END) AS android_total';
        $todayCounts = MapUsageEvent::query()
            ->where('loaded_at', '>=', $today->utc()->format('Y-m-d H:i:s'))
            ->where('loaded_at', '<', $today->addDay()->utc()->format('Y-m-d H:i:s'))
            ->selectRaw($totalsExpression, [self::DRIVER_ANDROID_SURFACE])->first();
        $allTimeCounts = MapUsageEvent::query()
            ->selectRaw($totalsExpression.', MIN(loaded_at) AS first_loaded_at', [self::DRIVER_ANDROID_SURFACE])
            ->first();

        return [
            'month' => $month,
            'monthlyTotal' => $total,
            'monthlyWebTotal' => $webTotal,
            'monthlyAndroidTotal' => $androidTotal,
            'todayTotal' => (int) $todayCounts->total,
            'todayWebTotal' => (int) $todayCounts->total - (int) $todayCounts->android_total,
            'todayAndroidTotal' => (int) $todayCounts->android_total,
            'allTimeTotal' => (int) $allTimeCounts->total,
            'allTimeWebTotal' => (int) $allTimeCounts->total - (int) $allTimeCounts->android_total,
            'allTimeAndroidTotal' => (int) $allTimeCounts->android_total,
            'firstRecordedAt' => $allTimeCounts->first_loaded_at,
            'surfaces' => collect(self::SURFACES)->map(fn (string $label, string $surface): array => [
                'label' => $label,
                'total' => (int) ($bySurface[$surface] ?? 0),
            ])->values(),
            'daily' => $daily,
            'target' => $target,
            'targetPercentage' => min(100, (int) floor($webTotal / $target * 100)),
        ];
    }
}
