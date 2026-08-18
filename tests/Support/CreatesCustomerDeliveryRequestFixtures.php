<?php

namespace Tests\Support;

use App\Models\Business;
use App\Models\BusinessBranch;
use App\Models\Customer;
use App\Models\CustomerDeliveryRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerDeliveryRequestService;
use App\Services\CustomerDeliveryRequestSessionService;
use Illuminate\Support\Str;

trait CreatesCustomerDeliveryRequestFixtures
{
    protected function deliveryRequestBusiness(string $name = 'Request Business'): Business
    {
        return Business::query()->create([
            'name' => $name,
            'business_code' => Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
    }

    protected function deliveryRequestRole(string $name): Role
    {
        return Role::query()->firstOrCreate(
            ['name' => $name],
            ['display_name' => Str::headline($name)]
        );
    }

    protected function deliveryRequestUser(
        string $role,
        ?Business $business = null,
        string $status = 'active'
    ): User {
        return User::query()->create([
            'business_id' => $business?->id,
            'role_id' => $this->deliveryRequestRole($role)->id,
            'name' => Str::headline($role).' '.Str::random(5),
            'phone' => '2557'.random_int(10000000, 99999999),
            'email' => Str::random(8).'@request.test',
            'password' => 'password',
            'status' => $status,
        ]);
    }

    protected function deliveryRequestBranch(Business $business): BusinessBranch
    {
        return BusinessBranch::query()->create([
            'business_id' => $business->id,
            'name' => 'Request Branch',
            'phone' => '255700000002',
            'address' => 'Request Branch, Sinza',
            'latitude' => -6.7805000,
            'longitude' => 39.2195000,
            'status' => 'active',
        ]);
    }

    protected function deliveryRequestCustomer(Business $business, string $phone = '255712345678'): Customer
    {
        return Customer::query()->create([
            'business_id' => $business->id,
            'name' => 'Existing Customer',
            'phone' => $phone,
            'email' => 'existing@example.test',
            'status' => 'active',
        ]);
    }

    /**
     * @return array{delivery_request: CustomerDeliveryRequest, token: string}
     */
    protected function issueCustomerDeliveryRequest(User $owner, Business $business): array
    {
        return app(CustomerDeliveryRequestService::class)->issue($owner, $business->id);
    }

    protected function customerDeliveryRequestCookie(string $token): string
    {
        $cookieName = app(CustomerDeliveryRequestSessionService::class)->cookieName();
        $response = $this->get('/request-delivery/'.$token);

        return (string) $response->getCookie($cookieName)?->getValue();
    }

    /**
     * @return array<string, mixed>
     */
    protected function customerDeliveryRequestSubmission(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_name' => 'Asha Mteja',
            'customer_phone' => '255712345678',
            'customer_email' => 'asha@example.test',
            'dropoff_address' => 'Mikocheni, Dar es Salaam',
            'dropoff_latitude' => -6.7750000,
            'dropoff_longitude' => 39.2500000,
            'special_instruction' => 'Call at the gate',
            'items' => [
                [
                    'item_name' => 'Parcel',
                    'quantity' => 2,
                    'description' => 'Two sealed packages',
                ],
                [
                    'item_name' => 'Documents',
                    'quantity' => 1,
                    'description' => null,
                ],
            ],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    protected function customerDeliveryConversionPayload(
        CustomerDeliveryRequest $deliveryRequest,
        array $overrides = []
    ): array {
        return array_replace_recursive([
            'customer_resolution' => 'new',
            'customer_name' => $deliveryRequest->customer_name,
            'customer_phone' => $deliveryRequest->customer_phone,
            'customer_email' => $deliveryRequest->customer_email,
            'branch_id' => null,
            'pickup_name' => 'Shop dispatch',
            'pickup_phone' => '255700000001',
            'pickup_address' => 'Sinza, Dar es Salaam',
            'pickup_latitude' => -6.7800000,
            'pickup_longitude' => 39.2200000,
            'dropoff_address' => $deliveryRequest->dropoff_address,
            'dropoff_latitude' => $deliveryRequest->dropoff_latitude,
            'dropoff_longitude' => $deliveryRequest->dropoff_longitude,
            'payment_method' => 'cash_on_delivery',
            'amount_to_collect' => 18000,
            'delivery_fee' => 2000,
            'special_instruction' => $deliveryRequest->special_instruction,
            'items' => $deliveryRequest->items->map(fn ($item): array => [
                'item_name' => $item->item_name,
                'quantity' => $item->quantity,
                'amount' => 9000,
                'description' => $item->description,
            ])->all(),
        ], $overrides);
    }
}
