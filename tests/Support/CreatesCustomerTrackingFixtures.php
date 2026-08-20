<?php

namespace Tests\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryPayment;
use App\Models\DeliveryTrackingLocation;
use App\Models\DeliveryTrackingSession;
use App\Models\DriverProfile;
use App\Models\FailedDeliveryReason;
use App\Models\Role;
use App\Models\User;
use App\Services\LiveDeliveryLocationStore;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait CreatesCustomerTrackingFixtures
{
    protected function customerTrackingRole(string $name): Role
    {
        return Role::query()->firstOrCreate(
            ['name' => $name],
            ['display_name' => Str::headline($name)]
        );
    }

    protected function customerTrackingBusiness(): Business
    {
        return Business::query()->create([
            'name' => 'Business '.Str::random(6),
            'business_code' => Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
    }

    protected function customerTrackingUser(
        string $roleName,
        ?Business $business = null,
        string $status = 'active'
    ): User {
        return User::query()->create([
            'business_id' => $business?->id,
            'role_id' => $this->customerTrackingRole($roleName)->id,
            'name' => Str::headline($roleName).' '.Str::random(5),
            'phone' => '2557'.random_int(10000000, 99999999),
            'email' => Str::random(8).'@example.test',
            'password' => 'password',
            'status' => $status,
        ]);
    }

    protected function customerTrackingDriver(Business $business): User
    {
        $driver = $this->customerTrackingUser('driver', $business);

        DriverProfile::query()->create([
            'business_id' => $business->id,
            'user_id' => $driver->id,
            'vehicle_type' => 'bodaboda',
            'vehicle_number' => 'MC '.random_int(100, 999),
            'license_number' => 'LIC'.random_int(1000, 9999),
            'is_available' => true,
            'current_status' => 'on_delivery',
        ]);

        return $driver;
    }

    protected function customerTrackingCustomer(Business $business): Customer
    {
        return Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Customer '.Str::random(5),
            'phone' => '2556'.random_int(10000000, 99999999),
            'email' => Str::random(8).'@customer.test',
            'status' => 'active',
        ]);
    }

    protected function customerTrackingDelivery(
        Business $business,
        ?User $driver = null,
        string $status = 'assigned',
        bool $started = false
    ): Delivery {
        $customer = $this->customerTrackingCustomer($business);
        $startedAt = $started ? now()->subMinutes(2) : null;
        $delivery = Delivery::query()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'assigned_driver_id' => $driver?->id,
            'assigned_at' => $driver ? now() : null,
            'delivery_number' => 'PD-TEST-'.Str::upper(Str::random(8)),
            'tracking_code' => 'TRK-'.Str::upper(Str::random(10)),
            'public_tracking_token' => Str::random(80),
            'status' => $status,
            'pickup_name' => 'Main Shop',
            'pickup_phone' => '255700000001',
            'pickup_address' => 'Mikocheni',
            'dropoff_name' => $customer->name,
            'dropoff_phone' => $customer->phone,
            'dropoff_address' => 'Test address',
            'dropoff_latitude' => -6.7924000,
            'dropoff_longitude' => 39.2083000,
            'payment_method' => 'cash_on_delivery',
            'amount_to_collect' => 5000,
            'delivery_fee' => 1000,
            'started_at' => $startedAt,
        ]);

        DeliveryPayment::query()->create([
            'delivery_id' => $delivery->id,
            'business_id' => $business->id,
            'driver_id' => $driver?->id,
            'payment_method' => 'cash',
            'expected_amount' => 5000,
            'collected_amount' => 0,
            'payment_status' => 'pending',
        ]);

        if ($started && $driver) {
            DeliveryTrackingSession::query()->create([
                'delivery_id' => $delivery->id,
                'driver_id' => $driver->id,
                'status' => 'active',
                'started_at' => $startedAt,
            ]);
        }

        return $delivery;
    }

    protected function activeCustomerTrackingDelivery(Business $business, User $driver): Delivery
    {
        return $this->customerTrackingDelivery($business, $driver, 'on_the_way', true);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function customerTrackingLocation(
        Delivery $delivery,
        User $driver,
        array $overrides = []
    ): DeliveryTrackingLocation {
        return DeliveryTrackingLocation::query()->create(array_merge([
            'tracking_session_id' => $delivery->trackingSessions()
                ->where('status', 'active')
                ->value('id'),
            'delivery_id' => $delivery->id,
            'driver_id' => $driver->id,
            'latitude' => -6.7924000,
            'longitude' => 39.2083000,
            'accuracy' => 8.5,
            'speed' => 6.2,
            'heading' => 180,
            'battery_level' => 80,
            'recorded_at' => now()->subSeconds(5),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function customerTrackingLocationPayload(array $overrides = []): array
    {
        return array_merge([
            'latitude' => -6.7924,
            'longitude' => 39.2083,
            'accuracy' => 8.5,
            'speed' => 6.2,
            'heading' => 180.0,
            'battery_level' => 80,
            'recorded_at' => now()->subSeconds(5)->toISOString(),
        ], $overrides);
    }

    protected function putCustomerTrackingLiveLocation(Delivery $delivery, User $driver): DeliveryTrackingLocation
    {
        $location = $this->customerTrackingLocation($delivery, $driver);
        $session = $delivery->trackingSessions()->where('status', 'active')->firstOrFail();

        app(LiveDeliveryLocationStore::class)->storeLatest($delivery, $session, $location);

        return $location;
    }

    protected function customerTrackingCookieResponse(Delivery $delivery): TestResponse
    {
        return $this->get("/track/{$delivery->public_tracking_token}");
    }

    protected function customerTrackingCookieValue(Delivery $delivery): string
    {
        $cookieName = (string) config('pelekapro.customer_tracking.cookie_name');
        $cookie = $this->customerTrackingCookieResponse($delivery)->getCookie($cookieName);

        $this->assertNotNull($cookie);

        return (string) $cookie->getValue();
    }

    protected function customerTrackingFailureReason(): FailedDeliveryReason
    {
        return FailedDeliveryReason::query()->create([
            'name' => 'Customer unavailable '.Str::random(5),
            'is_active' => true,
        ]);
    }
}
