<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\User;
use App\Services\ApiUserEligibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(LoginRequest $request, ApiUserEligibility $eligibility): JsonResponse
    {
        $validated = $request->validated();
        $user = $this->findUser($validated);

        if (! $user
            || ! is_string($user->password)
            || ! Hash::check($validated['password'], $user->password)
            || ! $eligibility->allows($user)
        ) {
            return $this->invalidCredentialsResponse();
        }

        $expirationMinutes = max(1, (int) config('sanctum.expiration', 43200));
        $tokenName = $user->isDriver() ? 'flutter-driver' : 'pelekapro-api';
        $abilities = $user->isDriver() ? ['driver-api'] : ['api'];

        $newAccessToken = DB::transaction(function () use ($user, $tokenName, $abilities, $expirationMinutes) {
            $user->forceFill(['last_login_at' => now()])->save();

            return $user->createToken(
                $tokenName,
                $abilities,
                now()->addMinutes($expirationMinutes),
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'access_token' => $newAccessToken->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $newAccessToken->accessToken->expires_at?->toISOString(),
                'user' => (new AuthenticatedUserResource($user))->resolve($request),
            ],
        ])->withHeaders($this->noStoreHeaders());
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing(['role', 'driverProfile']);

        return response()->json([
            'success' => true,
            'data' => (new AuthenticatedUserResource($user))->resolve($request),
        ])->withHeaders($this->noStoreHeaders());
    }

    public function logout(Request $request): JsonResponse
    {
        $accessToken = $request->user()->currentAccessToken();

        if (! $accessToken instanceof PersonalAccessToken) {
            return response()->json([
                'success' => false,
                'message' => 'A bearer token is required.',
            ], 401)->withHeaders($this->noStoreHeaders());
        }

        $accessToken->delete();

        return response()->json([
            'success' => true,
            'message' => 'Current token revoked.',
        ])->withHeaders($this->noStoreHeaders());
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'All tokens revoked.',
        ])->withHeaders($this->noStoreHeaders());
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function findUser(array $validated): ?User
    {
        $query = User::query()
            ->withTrashed()
            ->with(['role', 'driverProfile']);

        if (isset($validated['phone'])) {
            return $query->where('phone', $validated['phone'])->first();
        }

        if (isset($validated['email'])) {
            return $this->findByEmail($query, $validated['email']);
        }

        $login = $validated['login'];

        return str_contains($login, '@')
            ? $this->findByEmail($query, $login)
            : $query->where('phone', $login)->first();
    }

    private function findByEmail(Builder $query, string $email): ?User
    {
        return $query
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->first();
    }

    private function invalidCredentialsResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'The provided credentials are invalid.',
        ], 422)->withHeaders($this->noStoreHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function noStoreHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ];
    }
}
