<?php

namespace Tests\Feature;

use App\Models\CustomerDeliveryRequest;
use App\Models\Delivery;
use App\Models\User;
use App\Services\CustomerDeliveryRequestService;
use App\Services\CustomerDeliveryRequestSessionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesCustomerDeliveryRequestFixtures;
use Tests\TestCase;

class CustomerProvidedDeliveryDetailsTest extends TestCase
{
    use CreatesCustomerDeliveryRequestFixtures;
    use RefreshDatabase;

    public static function businessRoles(): array
    {
        return [
            'owner' => ['business_owner'],
            'admin' => ['business_admin'],
            'super admin' => ['super_admin'],
        ];
    }

    #[DataProvider('businessRoles')]
    public function test_review_shows_saved_customer_details_as_text_and_starts_with_an_owner_item_row(string $role): void
    {
        [$user, $request] = $this->submittedRequest($role);

        $response = $this->actingAs($user, 'web')
            ->withSession(['_old_input' => [
                'customer_name' => 'Forged retry name',
                'customer_phone' => '255799999999',
                'dropoff_address' => 'Forged retry address',
                'dropoff_latitude' => 45,
                'dropoff_longitude' => 60,
            ]])
            ->get(route('portal.delivery-requests.show', $request));

        $response->assertOk()
            ->assertSee('Asha Mteja')
            ->assertSee('255712345678')
            ->assertSee('Mikocheni, Dar es Salaam')
            ->assertSee('-6.7750000, 39.2500000')
            ->assertSee('Provided by the customer and cannot be edited here.')
            ->assertDontSee('Forged retry name')
            ->assertDontSee('Forged retry address')
            ->assertSee('name="items[0][item_name]"', false)
            ->assertSee('name="items[0][quantity]"', false)
            ->assertSee('name="items[0][amount]"', false)
            ->assertSee('data-add-delivery-item', false)
            ->assertSee('name="payment_method"', false)
            ->assertSee('name="branch_id"', false);

        foreach (['customer_name', 'customer_phone', 'dropoff_address', 'dropoff_latitude', 'dropoff_longitude'] as $field) {
            $response->assertDontSee('name="'.$field.'"', false);
        }

        $this->assertDatabaseCount('customer_delivery_request_items', 0);
    }

    #[DataProvider('businessRoles')]
    public function test_conversion_uses_only_saved_contact_and_destination_despite_browser_overrides(string $role): void
    {
        [$user, $request] = $this->submittedRequest($role);

        $this->actingAs($user, 'web')
            ->post(route('portal.delivery-requests.convert', $request), $this->customerDeliveryConversionPayload($request, [
                'customer_name' => 'Owner replacement',
                'customer_phone' => '255799999999',
                'customer_address_id' => 999999,
                'dropoff_name' => 'Alternate recipient',
                'dropoff_phone' => '255788888888',
                'dropoff_address' => 'Owner replacement address',
                'dropoff_latitude' => 45,
                'dropoff_longitude' => 60,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $delivery = Delivery::query()->sole();
        $this->assertCustomerDetails($delivery);
        $this->assertSame('Asha Mteja', $delivery->customer->name);
        $this->assertSame('255712345678', $delivery->customer->phone);
        $this->assertSame('Mikocheni, Dar es Salaam', $delivery->customerAddress->street);
        $this->assertSame('-6.7750000', $delivery->customerAddress->latitude);
        $this->assertSame('39.2500000', $delivery->customerAddress->longitude);
        $this->assertSame('Handle with care', $delivery->special_instruction);
        $this->assertSame('18000.00', $delivery->payment->expected_amount);
        $this->assertSame('2000.00', $delivery->delivery_fee);
        $this->assertSame(['Parcel', 'Documents'], $delivery->items()->orderBy('id')->pluck('item_name')->all());
        $this->assertDatabaseCount('customer_delivery_request_items', 0);
        $this->assertSame('Asha Mteja', $request->refresh()->customer_name);
        $this->assertNull($request->special_instruction);
        $this->assertSame('converted', $request->status);
    }

    public function test_conversion_service_uses_the_locked_database_request_not_a_modified_model_or_payload(): void
    {
        [$user, $request] = $this->submittedRequest();
        $payload = $this->customerDeliveryConversionPayload($request, [
            'customer_name' => 'Forged name',
            'customer_phone' => '255799999999',
            'dropoff_address' => 'Forged address',
            'dropoff_latitude' => 40,
            'dropoff_longitude' => 50,
        ]);
        $request->forceFill([
            'customer_name' => 'Unsaved name',
            'customer_phone' => '255788888888',
            'dropoff_address' => 'Unsaved address',
            'dropoff_latitude' => 30,
            'dropoff_longitude' => 20,
        ]);

        $delivery = app(CustomerDeliveryRequestService::class)->convert($request, $payload, $user);

        $this->assertCustomerDetails($delivery);
        $this->assertSame('Asha Mteja', $delivery->customer->name);
        $this->assertSame('255712345678', $delivery->customer->phone);
        $this->assertSame('converted', $request->refresh()->status);
    }

    public function test_browser_cannot_change_phone_to_match_a_different_existing_customer(): void
    {
        [$user, $request] = $this->submittedRequest();
        $differentCustomer = $this->deliveryRequestCustomer($user->business, '255799999999');
        $payload = $this->customerDeliveryConversionPayload($request, [
            'customer_resolution' => 'existing',
            'customer_id' => $differentCustomer->id,
            'customer_phone' => $differentCustomer->phone,
        ]);

        $this->actingAs($user, 'web')
            ->from(route('portal.delivery-requests.show', $request))
            ->post(route('portal.delivery-requests.convert', $request), $payload)
            ->assertSessionHasErrors('customer_id');

        // The same protection applies to callers that do not use the form request.
        try {
            app(CustomerDeliveryRequestService::class)->convert($request, $payload, $user);
            $this->fail('A different customer phone must not be accepted.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('deliveries', 0);
            $this->assertDatabaseCount('customer_addresses', 0);
            $this->assertSame('submitted', $request->refresh()->status);
            $this->assertSame('255712345678', $request->customer_phone);
        }
    }

    public function test_owner_must_enter_items_and_valid_prices_before_creating_the_delivery(): void
    {
        [$user, $request] = $this->submittedRequest();
        $payload = $this->customerDeliveryConversionPayload($request);
        unset($payload['items']);

        $this->actingAs($user, 'web')
            ->from(route('portal.delivery-requests.show', $request))
            ->post(route('portal.delivery-requests.convert', $request), $payload)
            ->assertSessionHasErrors('items')
            ->assertSessionDoesntHaveErrors([
                'customer_name', 'customer_phone', 'dropoff_address', 'dropoff_latitude', 'dropoff_longitude',
            ]);

        $payload['items'] = [['item_name' => 'Owner item', 'quantity' => 0, 'amount' => -1]];
        $this->actingAs($user, 'web')
            ->from(route('portal.delivery-requests.show', $request))
            ->post(route('portal.delivery-requests.convert', $request), $payload)
            ->assertSessionHasErrors(['items.0.quantity', 'items.0.amount']);

        $this->get(route('portal.delivery-requests.show', $request))
            ->assertOk()
            ->assertSee('Owner item')
            ->assertSee('Asha Mteja')
            ->assertSee('Mikocheni, Dar es Salaam')
            ->assertDontSee('name="customer_name"', false);

        $this->assertSame('submitted', $request->refresh()->status);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('delivery_payments', 0);
    }

    public function test_legacy_request_items_are_preserved_for_owner_review(): void
    {
        [$user, $request] = $this->submittedRequest();
        $legacyItem = $request->items()->create([
            'item_name' => 'Previously submitted item',
            'quantity' => 3,
            'description' => 'Previously submitted description',
        ]);

        $this->actingAs($user, 'web')->get(route('portal.delivery-requests.show', $request))
            ->assertOk()
            ->assertSee('Previously submitted item')
            ->assertSee('name="items[0][amount]"', false);

        $this->post(route('portal.delivery-requests.convert', $request), $this->customerDeliveryConversionPayload($request))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('customer_delivery_request_items', [
            'id' => $legacyItem->id,
            'item_name' => 'Previously submitted item',
            'quantity' => 3,
        ]);
        $this->assertSame(['Parcel', 'Documents'], Delivery::query()->sole()->items()->orderBy('id')->pluck('item_name')->all());
    }

    public static function deliveryEditPaths(): array
    {
        return [
            'portal' => ['web', false],
            'API' => ['api', false],
            'portal with archived request' => ['web', true],
            'API with archived request' => ['api', true],
        ];
    }

    #[DataProvider('deliveryEditPaths')]
    public function test_later_delivery_edits_cannot_override_customer_details_but_can_update_business_fields(string $via, bool $archive): void
    {
        [$user, $request] = $this->submittedRequest();
        $delivery = app(CustomerDeliveryRequestService::class)->convert(
            $request,
            $this->customerDeliveryConversionPayload($request),
            $user
        );
        $customerId = $delivery->customer_id;
        $addressId = $delivery->customer_address_id;

        if ($archive) {
            $request->delete();
        }

        $this->actingAs($user, 'web')->get(route('portal.deliveries.edit', $delivery))
            ->assertOk()
            ->assertSee('Asha Mteja')
            ->assertSee('Mikocheni, Dar es Salaam')
            ->assertDontSee('name="dropoff_name"', false)
            ->assertDontSee('name="dropoff_phone"', false)
            ->assertDontSee('name="dropoff_address"', false)
            ->assertDontSee('name="dropoff_latitude"', false)
            ->assertDontSee('name="dropoff_longitude"', false)
            ->assertSee('name="items[0][item_name]"', false);

        $differentCustomer = $this->deliveryRequestCustomer($user->business, '255799999999');
        $payload = [
            'customer_id' => $differentCustomer->id,
            'customer_address_id' => null,
            'dropoff_name' => 'Edited recipient',
            'dropoff_phone' => '255788888888',
            'dropoff_address' => 'Edited destination',
            'dropoff_latitude' => 40,
            'dropoff_longitude' => 50,
            'pickup_address' => 'Updated shop address',
            'amount_to_collect' => 25000,
            'delivery_fee' => 3000,
            'special_instruction' => 'Updated owner instruction',
            'items' => [['item_name' => 'Owner replacement item', 'quantity' => 1, 'amount' => 25000]],
        ];

        if ($via === 'api') {
            auth('web')->logout();
            $this->withToken($user->createToken('customer-detail-protection-test')->plainTextToken)
                ->putJson('/api/deliveries/'.$delivery->id, $payload)
                ->assertOk();
        } else {
            $this->put(route('portal.deliveries.update', $delivery), $payload)
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('portal.deliveries.show', $delivery));
        }

        $this->assertCustomerDetails($delivery->refresh());
        $this->assertSame($customerId, $delivery->customer_id);
        $this->assertSame($addressId, $delivery->customer_address_id);
        $this->assertSame('Updated shop address', $delivery->pickup_address);
        $this->assertSame('25000.00', $delivery->amount_to_collect);
        $this->assertSame('25000.00', $delivery->payment->expected_amount);
        $this->assertSame('3000.00', $delivery->delivery_fee);
        $this->assertSame('Updated owner instruction', $delivery->special_instruction);
        $this->assertSame('Owner replacement item', $delivery->items()->sole()->item_name);
    }

    /** @return array{User, CustomerDeliveryRequest} */
    private function submittedRequest(string $role = 'business_owner'): array
    {
        $business = $this->deliveryRequestBusiness();
        $user = $this->deliveryRequestUser($role, $business);
        $issued = $this->issueCustomerDeliveryRequest($user, $business);
        $cookieName = app(CustomerDeliveryRequestSessionService::class)->cookieName();
        $cookieValue = $this->customerDeliveryRequestCookie($issued['token']);

        $this->withCredentials()->withCookie($cookieName, $cookieValue)
            ->post('/delivery-request/session', $this->customerDeliveryRequestSubmission())
            ->assertRedirect(route('customer.delivery-request.submitted'));

        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return [$user, $issued['delivery_request']->refresh()];
    }

    private function assertCustomerDetails(Delivery $delivery): void
    {
        $this->assertSame('Asha Mteja', $delivery->dropoff_name);
        $this->assertSame('255712345678', $delivery->dropoff_phone);
        $this->assertSame('Mikocheni, Dar es Salaam', $delivery->dropoff_address);
        $this->assertSame('-6.7750000', $delivery->dropoff_latitude);
        $this->assertSame('39.2500000', $delivery->dropoff_longitude);
    }
}
