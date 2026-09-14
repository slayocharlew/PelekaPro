<?php

namespace Tests\Feature;

use App\Broadcasting\BusinessLiveDeliveriesChannel;
use App\Models\Business;
use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessLiveDeliveryChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-reverb-key',
            'broadcasting.connections.reverb.secret' => 'test-reverb-secret',
            'broadcasting.connections.reverb.app_id' => 'test-reverb-app',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        Broadcast::purge('reverb');
        Broadcast::channel(
            'business.{businessId}.live-deliveries',
            BusinessLiveDeliveriesChannel::class,
            ['guards' => ['web']]
        );
    }

    public function test_active_owner_and_admin_can_authorize_only_their_business_channel(): void
    {
        $business = $this->business();
        $otherBusiness = $this->business();
        $owner = $this->userWithRole('business_owner', $business);
        $admin = $this->userWithRole('business_admin', $business);

        $this->authorizeAs($owner, $business)->assertOk()->assertJsonStructure(['auth']);
        $this->authorizeAs($admin, $business)->assertOk()->assertJsonStructure(['auth']);

        $this->authorizeAs($owner, $otherBusiness)->assertForbidden();
        $this->authorizeAs($admin, $otherBusiness)->assertForbidden();
    }

    public function test_driver_customer_and_unauthenticated_users_are_denied(): void
    {
        $business = $this->business();
        $driver = $this->userWithRole('driver', $business);
        $customer = $this->userWithRole('customer', $business);

        DriverProfile::query()->create([
            'business_id' => $business->id,
            'user_id' => $driver->id,
            'vehicle_type' => 'bodaboda',
            'vehicle_number' => 'MC 123',
            'license_number' => 'LIC1234',
            'is_available' => true,
            'current_status' => 'available',
        ]);

        $this->authorizeAs($driver, $business)->assertForbidden();
        $this->authorizeAs($customer, $business)->assertForbidden();

        $this->postJson('/broadcasting/auth', $this->authorizationPayload($business))
            ->assertForbidden();
    }

    public function test_inactive_suspended_and_soft_deleted_users_are_denied(): void
    {
        $business = $this->business();
        $inactive = $this->userWithRole('business_owner', $business, 'inactive');
        $suspended = $this->userWithRole('business_admin', $business, 'suspended');
        $deleted = $this->userWithRole('business_owner', $business);
        $deleted->delete();

        $this->authorizeAs($inactive, $business)->assertForbidden();
        $this->authorizeAs($suspended, $business)->assertForbidden();
        $this->authorizeAs($deleted, $business)->assertForbidden();
    }

    public function test_super_admin_can_authorize_an_existing_business_channel(): void
    {
        $business = $this->business();
        $superAdmin = $this->userWithRole('super_admin');

        $this->authorizeAs($superAdmin, $business)->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_malformed_and_nonexistent_business_ids_are_denied_without_distinguishing_them(): void
    {
        $business = $this->business();
        $owner = $this->userWithRole('business_owner', $business);

        $malformed = $this->actingAs($owner, 'web')->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-business.invalid.live-deliveries',
        ]);
        $nonexistent = $this->actingAs($owner, 'web')->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-business.999999.live-deliveries',
        ]);

        $malformed->assertForbidden();
        $nonexistent->assertForbidden();
        $this->assertSame($malformed->getStatusCode(), $nonexistent->getStatusCode());
        $this->assertSame($malformed->getContent(), $nonexistent->getContent());
    }

    public function test_public_tracking_and_query_string_tokens_cannot_authorize_the_channel(): void
    {
        $business = $this->business();

        $this->withHeader('Authorization', 'Bearer public-tracking-token')
            ->postJson('/broadcasting/auth', $this->authorizationPayload($business))
            ->assertForbidden();

        $this->postJson('/broadcasting/auth?token=query-string-token', $this->authorizationPayload($business))
            ->assertForbidden();
    }

    public function test_broadcasting_authorization_keeps_web_csrf_protection(): void
    {
        $business = $this->business();
        $owner = $this->userWithRole('business_owner', $business);
        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'local';

        try {
            $this->actingAs($owner, 'web')
                ->postJson('/broadcasting/auth', $this->authorizationPayload($business))
                ->assertStatus(419);

            $this->withSession(['_token' => 'known-csrf-token'])
                ->withHeader('X-CSRF-TOKEN', 'known-csrf-token')
                ->actingAs($owner, 'web')
                ->postJson('/broadcasting/auth', $this->authorizationPayload($business))
                ->assertOk()
                ->assertJsonStructure(['auth']);
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
    }

    private function authorizeAs(User $user, Business $business)
    {
        return $this->actingAs($user, 'web')
            ->postJson('/broadcasting/auth', $this->authorizationPayload($business));
    }

    /**
     * @return array<string, string>
     */
    private function authorizationPayload(Business $business): array
    {
        return [
            'socket_id' => '1234.5678',
            'channel_name' => "private-business.{$business->id}.live-deliveries",
        ];
    }

    private function role(string $name): Role
    {
        return Role::query()->firstOrCreate(
            ['name' => $name],
            ['display_name' => Str::headline($name)]
        );
    }

    private function business(): Business
    {
        return Business::query()->create([
            'name' => 'Business '.Str::random(6),
            'business_code' => Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
    }

    private function userWithRole(
        string $roleName,
        ?Business $business = null,
        string $status = 'active'
    ): User {
        return User::query()->create([
            'business_id' => $business?->id,
            'role_id' => $this->role($roleName)->id,
            'name' => Str::headline($roleName).' '.Str::random(5),
            'phone' => '2557'.random_int(10000000, 99999999),
            'email' => Str::random(8).'@example.test',
            'password' => 'password',
            'status' => $status,
        ]);
    }
}
