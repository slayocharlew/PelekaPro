<?php

namespace App\Http\Controllers\Portal;

use App\Exceptions\DeliveryWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PortalConvertCustomerDeliveryRequestRequest;
use App\Http\Requests\PortalCreateCustomerDeliveryRequestRequest;
use App\Models\Business;
use App\Models\BusinessBranch;
use App\Models\Customer;
use App\Models\CustomerDeliveryRequest;
use App\Services\CustomerDeliveryRequestService;
use App\Services\DeliveryManagementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerDeliveryRequestController extends Controller
{
    public function __construct(
        private readonly CustomerDeliveryRequestService $deliveryRequests,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', CustomerDeliveryRequest::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...CustomerDeliveryRequest::STATUSES, 'expired'])],
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $user = $request->user('web');

        if (! $user->isSuperAdmin()) {
            unset($filters['business_id']);
        }

        $query = $this->deliveryRequests->scopedQuery($user)
            ->with(['business', 'createdBy', 'convertedDelivery'])
            ->when($filters['business_id'] ?? null, fn (Builder $query, int|string $businessId) => $query->where('business_id', $businessId))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function (Builder $query) use ($like): void {
                    $query
                        ->where('customer_name', 'like', $like)
                        ->orWhere('customer_phone', 'like', $like);
                });
            });

        if (($filters['status'] ?? null) === 'expired') {
            $query->where('status', 'pending')->where('expires_at', '<=', now());
        } elseif (($filters['status'] ?? null) === 'pending') {
            $query->where('status', 'pending')->where('expires_at', '>', now());
        } elseif (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return view('portal.delivery-requests.index', [
            'deliveryRequests' => $query->latest()->paginate(15)->withQueryString(),
            'businesses' => $user->isSuperAdmin() ? $this->activeBusinesses() : collect(),
            'filters' => $filters,
            'statuses' => [...CustomerDeliveryRequest::STATUSES, 'expired'],
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', CustomerDeliveryRequest::class);

        return view('portal.delivery-requests.create', [
            'businesses' => $request->user('web')->isSuperAdmin()
                ? $this->activeBusinesses()
                : collect(),
        ]);
    }

    public function store(
        PortalCreateCustomerDeliveryRequestRequest $request
    ): RedirectResponse {
        $issued = $this->deliveryRequests->issue(
            $request->user('web'),
            $request->resolvedBusinessId()
        );
        $deliveryRequest = $issued['delivery_request'];

        return redirect()
            ->route('portal.delivery-requests.show', $deliveryRequest)
            ->with('success', 'Customer request link created. Copy it now; it will not be shown again.')
            ->with('delivery_request_url', route('customer.delivery-request.enter', $issued['token']));
    }

    public function show(CustomerDeliveryRequest $customerDeliveryRequest): View
    {
        Gate::authorize('view', $customerDeliveryRequest);
        $customerDeliveryRequest->load(['business', 'createdBy', 'items', 'convertedDelivery']);
        $matchingCustomers = $customerDeliveryRequest->status === 'submitted'
            ? Customer::query()
                ->where('business_id', $customerDeliveryRequest->business_id)
                ->where('status', 'active')
                ->where('phone', $customerDeliveryRequest->customer_phone)
                ->orderBy('name')
                ->get()
            : collect();

        return view('portal.delivery-requests.show', [
            'deliveryRequest' => $customerDeliveryRequest,
            'matchingCustomers' => $matchingCustomers,
            'branches' => BusinessBranch::query()
                ->with('business:id,phone')
                ->where('business_id', $customerDeliveryRequest->business_id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(),
            'paymentMethods' => DeliveryManagementService::PAYMENT_METHODS,
            'requestUrl' => session('delivery_request_url'),
        ]);
    }

    public function regenerate(
        CustomerDeliveryRequest $customerDeliveryRequest
    ): RedirectResponse {
        Gate::authorize('regenerate', $customerDeliveryRequest);

        try {
            $issued = $this->deliveryRequests->regenerate($customerDeliveryRequest);
        } catch (DeliveryWorkflowException $exception) {
            return back()->withErrors(['delivery_request' => $exception->getMessage()]);
        }

        return redirect()
            ->route('portal.delivery-requests.show', $customerDeliveryRequest)
            ->with('success', 'A new customer request link was created. The previous link is no longer valid.')
            ->with('delivery_request_url', route('customer.delivery-request.enter', $issued['token']));
    }

    public function revoke(
        CustomerDeliveryRequest $customerDeliveryRequest
    ): RedirectResponse {
        Gate::authorize('revoke', $customerDeliveryRequest);

        try {
            $this->deliveryRequests->revoke($customerDeliveryRequest);
        } catch (DeliveryWorkflowException $exception) {
            return back()->withErrors(['delivery_request' => $exception->getMessage()]);
        }

        return back()->with('success', 'Customer delivery request revoked.');
    }

    public function convert(
        PortalConvertCustomerDeliveryRequestRequest $request,
        CustomerDeliveryRequest $customerDeliveryRequest
    ): RedirectResponse {
        try {
            $delivery = $this->deliveryRequests->convert(
                $customerDeliveryRequest,
                $request->validated(),
                $request->user('web')
            );
        } catch (DeliveryWorkflowException $exception) {
            return back()->withInput()->withErrors(['delivery_request' => $exception->getMessage()]);
        }

        return redirect()
            ->route('portal.deliveries.show', $delivery)
            ->with('success', 'Delivery created from the customer request. You can now assign a driver and share the separate tracking link.');
    }

    private function activeBusinesses()
    {
        return Business::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }
}
