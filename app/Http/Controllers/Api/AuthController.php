<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->with(['role', 'business', 'branch', 'driverProfile'])
            ->where('phone', $request->validated('phone'))
            ->first();

        if (! $user || ! is_string($user->password) || ! Hash::check($request->validated('password'), $user->password)) {
            return $this->error('The provided credentials are invalid.', 422);
        }

        if ($user->status !== 'active') {
            return $this->error('This account is not active.', 403);
        }

        if ($user->isDriver() && (! $user->driverProfile
            || $user->driverProfile->trashed()
            || $user->driverProfile->current_status === 'suspended')) {
            return $this->error('This driver profile is not active.', 403);
        }

        $expirationMinutes = max(1, (int) config('sanctum.expiration', 43200));
        $tokenName = $user->isDriver() ? 'flutter-driver' : 'pelekapro-api';
        $abilities = $user->isDriver() ? ['driver-api'] : ['api'];
        $token = $user->createToken($tokenName, $abilities, now()->addMinutes($expirationMinutes));

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'user' => new AuthenticatedUserResource($user),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['role', 'business', 'branch']);

        return response()->json([
            'success' => true,
            'message' => 'Authenticated user retrieved successfully',
            'data' => new AuthenticatedUserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return $this->error('Bearer token authentication is required.', 401);
        }

        $token->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout successful',
            'data' => [],
        ]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'All sessions logged out successfully',
            'data' => [],
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
