<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DeliveryWorkflowException;
use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Services\FirebaseTrackingCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FirebaseTrackingCredentialController extends Controller
{
    public function __invoke(
        Request $request,
        Delivery $delivery,
        FirebaseTrackingCredentialService $credentials,
    ): JsonResponse {
        try {
            $credential = $credentials->forDriver($delivery, $request->user());
        } catch (DeliveryWorkflowException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->statusCode());
        }

        return response()->json([
            'success' => true,
            'message' => 'Firebase tracking credential created.',
            'data' => $credential,
        ]);
    }
}
