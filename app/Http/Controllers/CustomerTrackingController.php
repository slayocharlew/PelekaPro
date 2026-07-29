<?php

namespace App\Http\Controllers;

use App\Auth\CustomerTrackingPrincipal;
use App\Models\Delivery;
use App\Services\CustomerTrackingSessionService;
use App\Services\CustomerTrackingSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class CustomerTrackingController extends Controller
{
    public function enter(
        Request $request,
        string $publicTrackingToken,
        CustomerTrackingSessionService $sessions
    ): RedirectResponse|Response {
        if (preg_match('/^[A-Za-z0-9]{80}$/', $publicTrackingToken) !== 1) {
            return $this->invalid();
        }

        $delivery = Delivery::query()
            ->where('public_tracking_token', $publicTrackingToken)
            ->first();

        if (! $delivery || ! hash_equals((string) $delivery->public_tracking_token, $publicTrackingToken)) {
            return $this->invalid();
        }

        return redirect()
            ->route('customer.tracking.page')
            ->withCookie($sessions->cookieForDelivery($delivery, $request));
    }

    public function page(): View|Response
    {
        $principal = auth('customer_tracking')->user();

        if (! $principal instanceof CustomerTrackingPrincipal) {
            return response()
                ->view('tracking.invalid', status: 401);
        }

        return view('tracking.show', [
            'trackingSessionExpiresAt' => $principal->expiresAt,
        ]);
    }

    public function show(
        CustomerTrackingSnapshotService $snapshots
    ): JsonResponse {
        $principal = auth('customer_tracking')->user();

        abort_unless($principal instanceof CustomerTrackingPrincipal, 401);

        return response()->json($snapshots->forPrincipal($principal));
    }

    public function destroy(CustomerTrackingSessionService $sessions): Response
    {
        return response()
            ->noContent()
            ->withCookie($sessions->forgetCookie());
    }

    private function invalid(): Response
    {
        return response('Tracking access is invalid or expired.', 404);
    }
}
