<?php

namespace Tests\Feature;

use App\Models\CustomerDeliveryRequest;
use App\Models\Delivery;
use App\Services\CustomerDeliveryRequestSessionService;
use App\Services\CustomerDeliveryRequestTokenService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\Support\CreatesCustomerDeliveryRequestFixtures;
use Tests\TestCase;

class PortalCustomerDeliveryRequestTest extends TestCase
{
    use CreatesCustomerDeliveryRequestFixtures;
    use RefreshDatabase;

    public function test_owner_and_admin_can_view_only_their_business_requests(): void
    {
        $business = $this->deliveryRequestBusiness('Visible Request Business');
        $otherBusiness = $this->deliveryRequestBusiness('Hidden Request Business');
        $owner = $this->deliveryRequestUser('business_owner', $business);
        $admin = $this->deliveryRequestUser('business_admin', $business);
        $otherOwner = $this->deliveryRequestUser('business_owner', $otherBusiness);
        $visible = $this->issueCustomerDeliveryRequest($owner, $business)['delivery_request'];
        $hidden = $this->issueCustomerDeliveryRequest($otherOwner, $otherBusiness)['delivery_request'];

        foreach ([$owner, $admin] as $user) {
            $this->actingAs($user, 'web')
                ->get(route('portal.delivery-requests.index'))
                ->assertOk()
                ->assertSee('Request #'.$visible->id)
                ->assertDontSee('Request #'.$hidden->id);

            $this->actingAs($user, 'web')
                ->get(route('portal.delivery-requests.show', $hidden))
                ->assertForbidden();
        }
    }

    public function test_unauthenticated_driver_customer_and_inactive_users_cannot_access_request_portal(): void
    {
        $business = $this->deliveryRequestBusiness();
        $driver = $this->deliveryRequestUser('driver', $business);
        $customer = $this->deliveryRequestUser('customer', $business);
        $inactive = $this->deliveryRequestUser('business_owner', $business, 'inactive');
        $suspended = $this->deliveryRequestUser('business_admin', $business, 'suspended');
        $deleted = $this->deliveryRequestUser('business_owner', $business);
        $deleted->delete();

        $this->get(route('portal.delivery-requests.index'))->assertRedirect(route('login'));
        $this->actingAs($driver, 'web')->get(route('portal.delivery-requests.index'))->assertForbidden();
        $this->actingAs($customer, 'web')->get(route('portal.delivery-requests.index'))->assertForbidden();
        $this->actingAs($inactive, 'web')->get(route('portal.delivery-requests.index'))->assertForbidden();
        $this->actingAs($suspended, 'web')->get(route('portal.delivery-requests.index'))->assertForbidden();
        $this->actingAs($deleted, 'web')->get(route('portal.delivery-requests.index'))->assertForbidden();
    }

    public function test_owner_generates_hash_only_link_shown_once_without_browser_controlled_ownership(): void
    {
        $business = $this->deliveryRequestBusiness('Owner Request Business');
        $otherBusiness = $this->deliveryRequestBusiness('Injected Business');
        $owner = $this->deliveryRequestUser('business_owner', $business);

        $response = $this->actingAs($owner, 'web')
            ->post(route('portal.delivery-requests.store'), [
                'business_id' => $otherBusiness->id,
                'status' => 'converted',
                'token_hash' => str_repeat('a', 64),
                'expires_at' => now()->addYear()->toISOString(),
            ]);

        $deliveryRequest = CustomerDeliveryRequest::query()->sole();
        $requestUrl = session('delivery_request_url');
        $rawToken = basename((string) $requestUrl);

        $response->assertRedirect(route('portal.delivery-requests.show', $deliveryRequest))
            ->assertSessionHas('success');
        $this->assertSame($business->id, $deliveryRequest->business_id);
        $this->assertSame($owner->id, $deliveryRequest->created_by);
        $this->assertSame('pending', $deliveryRequest->status);
        $this->assertSame(80, strlen($rawToken));
        $this->assertSame(
            app(CustomerDeliveryRequestTokenService::class)->fingerprint($rawToken),
            $deliveryRequest->token_hash
        );
        $this->assertNotSame($rawToken, $deliveryRequest->token_hash);
        $this->assertLessThanOrEqual(24 * 60 * 60, $deliveryRequest->expires_at->timestamp - now()->timestamp);

        $this->actingAs($owner, 'web')
            ->get(route('portal.delivery-requests.show', $deliveryRequest))
            ->assertOk()
            ->assertSee($rawToken)
            ->assertSee('Replace link');

        $this->actingAs($owner, 'web')
            ->get(route('portal.delivery-requests.show', $deliveryRequest))
            ->assertOk()
            ->assertDontSee($rawToken)
            ->assertSee('Generate new link');
    }

    public function test_super_admin_must_select_an_active_business_for_new_request(): void
    {
        $superAdmin = $this->deliveryRequestUser('super_admin');
        $activeBusiness = $this->deliveryRequestBusiness('Selected Business');
        $inactiveBusiness = $this->deliveryRequestBusiness('Inactive Business');
        $inactiveBusiness->forceFill(['status' => 'inactive'])->save();

        $this->actingAs($superAdmin, 'web')
            ->from(route('portal.delivery-requests.create'))
            ->post(route('portal.delivery-requests.store'), [])
            ->assertSessionHasErrors('business_id');

        $this->actingAs($superAdmin, 'web')
            ->from(route('portal.delivery-requests.create'))
            ->post(route('portal.delivery-requests.store'), ['business_id' => $inactiveBusiness->id])
            ->assertSessionHasErrors('business_id');

        $this->actingAs($superAdmin, 'web')
            ->post(route('portal.delivery-requests.store'), ['business_id' => $activeBusiness->id])
            ->assertRedirect();

        $this->assertDatabaseHas('customer_delivery_requests', [
            'business_id' => $activeBusiness->id,
            'created_by' => $superAdmin->id,
        ]);
    }

    public function test_regeneration_invalidates_old_link_and_revocation_invalidates_new_link(): void
    {
        $business = $this->deliveryRequestBusiness();
        $owner = $this->deliveryRequestUser('business_owner', $business);
        $issued = $this->issueCustomerDeliveryRequest($owner, $business);
        $oldToken = $issued['token'];

        $response = $this->actingAs($owner, 'web')
            ->post(route('portal.delivery-requests.regenerate', $issued['delivery_request']));
        $newUrl = session('delivery_request_url');
        $newToken = basename((string) $newUrl);

        $response->assertRedirect(route('portal.delivery-requests.show', $issued['delivery_request']));
        $this->assertNotSame($oldToken, $newToken);
        $this->get('/request-delivery/'.$oldToken)->assertNotFound();
        $this->get('/request-delivery/'.$newToken)->assertRedirect(route('customer.delivery-request.page'));

        $this->actingAs($owner, 'web')
            ->post(route('portal.delivery-requests.revoke', $issued['delivery_request']))
            ->assertSessionHas('success');

        $this->assertSame('revoked', $issued['delivery_request']->refresh()->status);
        $this->assertNotNull($issued['delivery_request']->revoked_at);
        $this->get('/request-delivery/'.$newToken)->assertNotFound();
    }

    public function test_submitted_request_is_reviewed_and_atomically_converted_to_official_delivery(): void
    {
        $business = $this->deliveryRequestBusiness('Conversion Business');
        $branch = $this->deliveryRequestBranch($business);
        $owner = $this->deliveryRequestUser('business_owner', $business);
        $issued = $this->issueCustomerDeliveryRequest($owner, $business);
        $this->submitCustomerRequest($issued['token']);
        $deliveryRequest = $issued['delivery_request']->refresh()->load('items');
        $payload = $this->customerDeliveryConversionPayload($deliveryRequest, [
            'branch_id' => $branch->id,
            'business_id' => 999999,
            'assigned_driver_id' => 999999,
            'status' => 'delivered',
            'delivery_number' => 'BROWSER-CONTROLLED',
            'tracking_code' => 'BROWSER-TRACKING',
            'public_tracking_token' => 'browser-public-token',
            'delivery_pin' => '000000',
        ]);

        $response = $this->actingAs($owner, 'web')
            ->post(route('portal.delivery-requests.convert', $deliveryRequest), $payload);

        $delivery = Delivery::query()->sole();
        $deliveryRequest->refresh();
        $response->assertRedirect(route('portal.deliveries.show', $delivery))
            ->assertSessionHas('success');
        $this->assertSame('converted', $deliveryRequest->status);
        $this->assertSame($delivery->id, $deliveryRequest->converted_delivery_id);
        $this->assertNotNull($deliveryRequest->converted_at);
        $this->assertSame($business->id, $delivery->business_id);
        $this->assertSame($branch->id, $delivery->branch_id);
        $this->assertSame('Request Branch', $delivery->pickup_name);
        $this->assertSame('255700000002', $delivery->pickup_phone);
        $this->assertSame('Request Branch, Sinza', $delivery->pickup_address);
        $this->assertSame('-6.7805000', $delivery->pickup_latitude);
        $this->assertSame('39.2195000', $delivery->pickup_longitude);
        $this->assertSame($owner->id, $delivery->created_by);
        $this->assertNull($delivery->assigned_driver_id);
        $this->assertSame('location_confirmed', $delivery->status);
        $this->assertSame('Asha Mteja', $delivery->dropoff_name);
        $this->assertSame('255712345678', $delivery->dropoff_phone);
        $this->assertNotSame('BROWSER-CONTROLLED', $delivery->delivery_number);
        $this->assertNotSame('BROWSER-TRACKING', $delivery->tracking_code);
        $this->assertNotSame('browser-public-token', $delivery->public_tracking_token);
        $this->assertNotSame($issued['token'], $delivery->public_tracking_token);
        $this->assertNotSame('000000', $delivery->delivery_pin);
        $this->assertDatabaseHas('customers', [
            'id' => $delivery->customer_id,
            'business_id' => $business->id,
            'name' => 'Asha Mteja',
            'phone' => '255712345678',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('customer_addresses', [
            'id' => $delivery->customer_address_id,
            'business_id' => $business->id,
            'street' => 'Mikocheni, Dar es Salaam',
        ]);
        $this->assertDatabaseCount('delivery_items', 2);
        $this->assertDatabaseHas('delivery_payments', [
            'delivery_id' => $delivery->id,
            'business_id' => $business->id,
            'driver_id' => null,
            'payment_method' => 'cash',
            'expected_amount' => 18000,
        ]);
        $this->get('/request-delivery/'.$issued['token'])->assertNotFound();
    }

    public function test_existing_customer_can_be_reused_only_for_exact_phone_and_same_business(): void
    {
        $business = $this->deliveryRequestBusiness();
        $otherBusiness = $this->deliveryRequestBusiness('Other Customer Business');
        $owner = $this->deliveryRequestUser('business_owner', $business);
        $existing = $this->deliveryRequestCustomer($business);
        $otherCustomer = $this->deliveryRequestCustomer($otherBusiness);
        $issued = $this->issueCustomerDeliveryRequest($owner, $business);
        $this->submitCustomerRequest($issued['token']);
        $request = $issued['delivery_request']->refresh()->load('items');

        $this->actingAs($owner, 'web')
            ->from(route('portal.delivery-requests.show', $request))
            ->post(route('portal.delivery-requests.convert', $request), $this->customerDeliveryConversionPayload($request, [
                'customer_resolution' => 'existing',
                'customer_id' => $otherCustomer->id,
            ]))
            ->assertSessionHasErrors('customer_id');

        $this->assertDatabaseCount('deliveries', 0);

        $this->actingAs($owner, 'web')
            ->post(route('portal.delivery-requests.convert', $request), $this->customerDeliveryConversionPayload($request, [
                'customer_resolution' => 'existing',
                'customer_id' => $existing->id,
            ]))
            ->assertRedirect();

        $this->assertSame($existing->id, Delivery::query()->sole()->customer_id);
        $this->assertDatabaseCount('customers', 2);
    }

    public function test_cross_business_branch_and_duplicate_conversion_are_rejected(): void
    {
        $business = $this->deliveryRequestBusiness();
        $otherBusiness = $this->deliveryRequestBusiness('Other Branch Business');
        $owner = $this->deliveryRequestUser('business_owner', $business);
        $otherBranch = $this->deliveryRequestBranch($otherBusiness);
        $issued = $this->issueCustomerDeliveryRequest($owner, $business);
        $this->submitCustomerRequest($issued['token']);
        $request = $issued['delivery_request']->refresh()->load('items');

        $this->actingAs($owner, 'web')
            ->from(route('portal.delivery-requests.show', $request))
            ->post(route('portal.delivery-requests.convert', $request), $this->customerDeliveryConversionPayload($request, [
                'branch_id' => $otherBranch->id,
            ]))
            ->assertSessionHasErrors('branch_id');

        $this->assertDatabaseCount('deliveries', 0);

        $this->actingAs($owner, 'web')
            ->post(route('portal.delivery-requests.convert', $request), $this->customerDeliveryConversionPayload($request))
            ->assertRedirect();
        $this->assertDatabaseCount('deliveries', 1);

        $this->actingAs($owner, 'web')
            ->post(route('portal.delivery-requests.convert', $request), $this->customerDeliveryConversionPayload($request))
            ->assertSessionHasErrors('delivery_request');
        $this->assertDatabaseCount('deliveries', 1);
    }

    public function test_portal_navigation_keeps_manual_creation_and_request_creation_separate(): void
    {
        $business = $this->deliveryRequestBusiness();
        $owner = $this->deliveryRequestUser('business_owner', $business);

        $this->actingAs($owner, 'web')
            ->get(route('portal.deliveries.index'))
            ->assertOk()
            ->assertSee('Create manually')
            ->assertSee('Request customer details')
            ->assertSee(route('portal.deliveries.create'), false)
            ->assertSee(route('portal.delivery-requests.create'), false);

        $this->actingAs($owner, 'web')
            ->get(route('portal.deliveries.create'))
            ->assertOk()
            ->assertSee('name="customer_name"', false)
            ->assertSee('Request customer details instead');
    }

    public function test_request_routes_retain_web_session_role_and_csrf_protection(): void
    {
        $portalRoute = Route::getRoutes()->getByName('portal.delivery-requests.convert');
        $publicSubmit = Route::getRoutes()->getByName('customer.delivery-request.session.store');
        $webMiddleware = app(Router::class)->getMiddlewareGroups()['web'] ?? [];

        $this->assertSame(['POST'], $portalRoute?->methods());
        $this->assertContains('auth:web', $portalRoute?->gatherMiddleware() ?? []);
        $this->assertContains('active.web.user', $portalRoute?->gatherMiddleware() ?? []);
        $this->assertContains('role:super_admin,business_owner,business_admin', $portalRoute?->gatherMiddleware() ?? []);
        $this->assertSame(['POST'], $publicSubmit?->methods());
        $this->assertContains('customer.delivery-request', $publicSubmit?->gatherMiddleware() ?? []);
        $this->assertContains(PreventRequestForgery::class, $webMiddleware);
    }

    private function submitCustomerRequest(string $token): void
    {
        $cookieName = app(CustomerDeliveryRequestSessionService::class)->cookieName();
        $cookieValue = $this->customerDeliveryRequestCookie($token);

        $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->post('/delivery-request/session', $this->customerDeliveryRequestSubmission())
            ->assertRedirect(route('customer.delivery-request.submitted'));

        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }
}
