<?php

namespace App\Http\Controllers;

use App\Auth\CustomerDeliveryRequestPrincipal;
use App\Auth\CustomerTrackingPrincipal;
use App\Models\User;
use App\Services\ApiUserEligibility;
use App\Services\CustomerDeliveryRequestSessionService;
use App\Services\CustomerTrackingSessionService;
use App\Services\MapUsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MapUsageController extends Controller
{
    public function store(Request $request, string $surface, MapUsageService $usage): Response
    {
        abort_unless(array_key_exists($surface, MapUsageService::WEB_SURFACES), 404);
        $businessId = $this->authorizedBusinessId($request, $surface);
        $validator = Validator::make($request->only('event_id'), ['event_id' => ['required', 'uuid']]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid map usage report.'], 422)
                ->header('Cache-Control', 'no-store, private');
        }

        $data = $validator->validated();

        try {
            $usage->record($data['event_id'], $surface, $businessId);
        } catch (Throwable $exception) {
            // Measurement failures must not break maps, deliveries, or customer tracking.
            logger()->warning('Map usage recording unavailable.', [
                'surface' => $surface,
                'exception' => $exception::class,
            ]);

            return response()->json(['message' => 'Usage recording unavailable.'], 503)
                ->header('Cache-Control', 'no-store, private');
        }

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }

    private function authorizedBusinessId(Request $request, string $surface): ?int
    {
        if ($surface === 'customer_tracking') {
            $principal = app(CustomerTrackingSessionService::class)->principalFromRequest($request);
            abort_unless($principal instanceof CustomerTrackingPrincipal, 403);
            $delivery = app(CustomerTrackingSessionService::class)->deliveryForPrincipal($principal);
            abort_unless($delivery !== null, 403);

            return (int) $delivery->business_id;
        }

        if ($surface === 'customer_delivery_request') {
            $sessions = app(CustomerDeliveryRequestSessionService::class);
            $principal = $sessions->principalFromRequest($request);
            abort_unless($principal instanceof CustomerDeliveryRequestPrincipal, 403);
            $deliveryRequest = $sessions->deliveryRequestForPrincipal($principal);
            abort_unless($deliveryRequest !== null, 403);

            return (int) $deliveryRequest->business_id;
        }

        $user = $request->user('web');
        abort_unless($user instanceof User && app(ApiUserEligibility::class)->allows($user), 403);

        if ($surface === 'business_onboarding') {
            abort_unless($user->isSuperAdmin(), 403);

            return null;
        }

        abort_unless($user->isBusinessOwner() && $user->business_id !== null && $user->business !== null, 403);

        return (int) $user->business_id;
    }
}
