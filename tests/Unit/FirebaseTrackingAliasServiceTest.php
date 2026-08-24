<?php

namespace Tests\Unit;

use App\Models\Delivery;
use App\Models\DeliveryTrackingSession;
use App\Models\User;
use App\Services\FirebaseTrackingAliasService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FirebaseTrackingAliasServiceTest extends TestCase
{
    public function test_aliases_are_stable_opaque_and_scoped_to_authoritative_values(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $service = app(FirebaseTrackingAliasService::class);
        $delivery = new Delivery([
            'public_tracking_token' => str_repeat('A', 80),
        ]);
        $delivery->id = 123;
        $session = new DeliveryTrackingSession([
            'started_at' => Carbon::parse('2026-08-24T08:00:00Z'),
        ]);
        $session->id = 456;
        $driver = new User(['business_id' => 77]);
        $driver->id = 89;

        $deliveryAlias = $service->delivery($delivery);
        $sessionAlias = $service->session($delivery, $session);
        $driverUid = $service->driver($driver);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $deliveryAlias);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $sessionAlias);
        $this->assertMatchesRegularExpression('/^driver_[a-f0-9]{64}$/', $driverUid);
        $this->assertSame($deliveryAlias, $service->delivery($delivery));
        $this->assertSame($sessionAlias, $service->session($delivery, $session));
        $this->assertStringNotContainsString('123', $deliveryAlias);
        $this->assertStringNotContainsString('456', $sessionAlias);
        $this->assertNotSame($deliveryAlias, $sessionAlias);

        $delivery->public_tracking_token = str_repeat('B', 80);

        $this->assertNotSame($deliveryAlias, $service->delivery($delivery));
        $this->assertNotSame($sessionAlias, $service->session($delivery, $session));
    }
}
