<?php

namespace App\Services;

use App\Auth\CustomerTrackingPrincipal;
use App\Contracts\FirebaseTrackingStore;
use App\Exceptions\DeliveryWorkflowException;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Kreait\Firebase\Contract\Auth;

final class FirebaseTrackingCredentialService
{
    public function __construct(
        private readonly Container $container,
        private readonly DeliveryTrackingAuthority $authority,
        private readonly FirebaseTrackingStore $store,
        private readonly FirebaseTrackingAliasService $aliases,
        private readonly CustomerTrackingChannelAlias $customerAliases,
        private readonly CustomerTrackingSessionService $customerSessions,
        private readonly FirebaseTrackingSessionMode $firebaseMode,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function forDriver(Delivery $delivery, User $driver): array
    {
        $context = DB::transaction(
            fn (): array => $this->authority->activeContext($delivery, $driver, true)
        );
        $authoritativeDelivery = $context['delivery'];
        $session = $context['session'];

        if (! $this->firebaseMode->forDelivery($authoritativeDelivery, $session)) {
            throw new DeliveryWorkflowException('Firebase tracking is not enabled.', 409);
        }

        $control = $this->store->extendCredentialLease($authoritativeDelivery, $session, $driver);
        $expiresAtMs = (int) $control['access_expires_at_ms'];
        $claims = [
            'tracking_role' => 'driver',
            'delivery_alias' => $this->aliases->delivery($authoritativeDelivery),
            'session_alias' => (string) $control['session_alias'],
            'credential_version' => (string) $control['credential_version'],
            'access_expires_at_ms' => $expiresAtMs,
        ];
        $token = $this->auth()->createCustomToken(
            (string) $control['driver_uid'],
            $claims,
            $this->customTokenTtlSeconds()
        );

        return [
            'token' => $token->toString(),
            'delivery_alias' => $claims['delivery_alias'],
            'session_alias' => $claims['session_alias'],
            'database_path' => trim((string) config('pelekapro.firebase_tracking.root'), '/')
                .'/'.$claims['delivery_alias'],
            'expires_at' => intdiv($expiresAtMs, 1000),
        ];
    }

    /**
     * @return array<string, int|string>
     */
    public function forCustomer(CustomerTrackingPrincipal $principal): array
    {
        $delivery = $this->customerSessions->deliveryForPrincipal($principal);
        abort_unless($delivery instanceof Delivery, 401);
        abort_unless(
            $delivery->started_at !== null && in_array($delivery->status, ['on_the_way', 'arrived'], true),
            409
        );
        $sessions = $delivery->trackingSessions()
            ->where('status', 'active')
            ->whereNull('stopped_at')
            ->get();
        abort_unless($sessions->count() === 1, 409);
        $session = $sessions->first();
        abort_unless((string) $session->driver_id === (string) $delivery->assigned_driver_id, 409);
        abort_unless($this->firebaseMode->forDelivery($delivery, $session), 404);
        $this->store->assertCustomerScope($delivery, $session);

        $now = now()->getTimestamp();
        $expiresAt = min($principal->expiresAt, $now + $this->customTokenTtlSeconds());
        abort_if($expiresAt <= $now, 401);
        $deliveryAlias = $this->aliases->delivery($delivery);
        $fingerprint = $this->customerAliases->tokenFingerprint((string) $delivery->public_tracking_token);
        $claims = [
            'tracking_role' => 'customer',
            'delivery_alias' => $deliveryAlias,
            'token_fingerprint' => $fingerprint,
            'access_expires_at_ms' => $expiresAt * 1000,
        ];
        $token = $this->auth()->createCustomToken(
            'customer_'.substr($deliveryAlias, 0, 64),
            $claims,
            max(60, $expiresAt - $now)
        );

        return [
            'token' => $token->toString(),
            'delivery_alias' => $deliveryAlias,
            'database_path' => trim((string) config('pelekapro.firebase_tracking.root'), '/')
                .'/'.$deliveryAlias,
            'expires_at' => $expiresAt,
        ];
    }

    private function customTokenTtlSeconds(): int
    {
        return max(300, min(
            3600,
            (int) config('pelekapro.firebase_tracking.credential_lifetime_minutes', 30) * 60
        ));
    }

    private function auth(): Auth
    {
        return $this->container->make(Auth::class);
    }
}
