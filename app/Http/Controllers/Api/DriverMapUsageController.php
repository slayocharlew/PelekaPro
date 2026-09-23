<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDriverMapUsageRequest;
use App\Services\MapUsageService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DriverMapUsageController extends Controller
{
    public function store(StoreDriverMapUsageRequest $request, MapUsageService $usage): Response
    {
        $driver = $request->user();

        try {
            $usage->record(
                $request->validated('event_id'),
                MapUsageService::DRIVER_ANDROID_SURFACE,
                (int) $driver->business_id,
            );
        } catch (Throwable $exception) {
            // Telemetry failure must not interrupt maps or the delivery/GPS workflow.
            logger()->warning('Driver map usage recording unavailable.', [
                'user_id' => $driver->getKey(),
                'business_id' => $driver->business_id,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Usage recording unavailable.',
            ], 503)->header('Cache-Control', 'no-store, private');
        }

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }
}
