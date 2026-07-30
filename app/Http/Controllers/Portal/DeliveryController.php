<?php

namespace App\Http\Controllers\Portal;

use App\Exceptions\DeliveryWorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PortalAssignDriverRequest;
use App\Http\Requests\PortalCancelDeliveryRequest;
use App\Http\Requests\PortalStoreDeliveryRequest;
use App\Http\Requests\PortalUpdateDeliveryRequest;
use App\Models\Business;
use App\Models\BusinessBranch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Delivery;
use App\Models\User;
use App\Services\DeliveryAssignmentService;
use App\Services\DeliveryManagementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryManagementService $deliveries,
        private readonly DeliveryAssignmentService $assignments,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Delivery::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(DeliveryManagementService::STATUSES)],
            'assigned_driver_id' => ['nullable', 'integer'],
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'per_page' => ['nullable', 'integer', Rule::in([15, 30, 50])],
        ]);

        $user = $request->user('web');

        if (! $user->isSuperAdmin()) {
            unset($filters['business_id']);
        }

        $query = $this->deliveries->scopedQuery($user)
            ->with(['business', 'customer', 'assignedDriver', 'payment']);
        $this->deliveries->applyFilters($query, $filters);

        $deliveries = $query
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();
        $editableDeliveryIds = $deliveries->getCollection()
            ->filter(fn (Delivery $delivery): bool => $this->deliveries->isEditable($delivery))
            ->modelKeys();
        $assignableDeliveryIds = $deliveries->getCollection()
            ->filter(fn (Delivery $delivery): bool => $this->assignments->canChangeDriver($delivery))
            ->modelKeys();
        $cancellableDeliveryIds = $deliveries->getCollection()
            ->filter(fn (Delivery $delivery): bool => $this->deliveries->isCancellable($delivery))
            ->modelKeys();

        return view('portal.deliveries.index', [
            'deliveries' => $deliveries,
            'editableDeliveryIds' => $editableDeliveryIds,
            'assignableDeliveryIds' => $assignableDeliveryIds,
            'cancellableDeliveryIds' => $cancellableDeliveryIds,
            'drivers' => $this->filterDrivers($user, $filters['business_id'] ?? null),
            'businesses' => $user->isSuperAdmin() ? $this->activeBusinesses() : collect(),
            'statuses' => DeliveryManagementService::STATUSES,
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Delivery::class);

        return view('portal.deliveries.create', $this->formOptions($request->user('web')));
    }

    public function store(PortalStoreDeliveryRequest $request): RedirectResponse
    {
        Gate::authorize('create', Delivery::class);

        $delivery = $this->deliveries->create(
            $request->validated(),
            $request->resolvedBusinessId(),
            $request->user('web')
        );

        return redirect()
            ->route('portal.deliveries.show', $delivery)
            ->with('success', 'Delivery created successfully.');
    }

    public function show(Delivery $delivery): View
    {
        Gate::authorize('view', $delivery);
        $delivery->load($this->deliveries->relations());

        return view('portal.deliveries.show', [
            'delivery' => $delivery,
            'availableDrivers' => $this->assignments->availableDrivers($delivery->business_id),
            'canEdit' => Gate::allows('update', $delivery) && $this->deliveries->isEditable($delivery),
            'canAssign' => Gate::allows('assignDriver', $delivery) && $this->assignments->canChangeDriver($delivery),
            'canCancel' => Gate::allows('cancel', $delivery) && $this->deliveries->isCancellable($delivery),
            'trackingUrl' => route('customer.tracking.enter', $delivery->public_tracking_token),
        ]);
    }

    public function edit(Request $request, Delivery $delivery): View|RedirectResponse
    {
        Gate::authorize('update', $delivery);

        if (! $this->deliveries->isEditable($delivery)) {
            return redirect()
                ->route('portal.deliveries.show', $delivery)
                ->with('error', 'Delivery cannot be edited after it has started or reached a final status.');
        }

        $delivery->load(['items', 'customer', 'customerAddress']);

        return view('portal.deliveries.edit', array_merge(
            $this->formOptions($request->user('web'), $delivery->business_id),
            ['delivery' => $delivery]
        ));
    }

    public function update(
        PortalUpdateDeliveryRequest $request,
        Delivery $delivery
    ): RedirectResponse {
        Gate::authorize('update', $delivery);

        try {
            $this->deliveries->update($delivery, $request->validated(), $request->user('web'));
        } catch (DeliveryWorkflowException $exception) {
            return back()->withInput()->withErrors(['delivery' => $exception->getMessage()]);
        }

        return redirect()
            ->route('portal.deliveries.show', $delivery)
            ->with('success', 'Delivery updated successfully.');
    }

    public function assign(
        PortalAssignDriverRequest $request,
        Delivery $delivery
    ): RedirectResponse {
        Gate::authorize('assignDriver', $delivery);

        $driver = User::query()
            ->with(['role', 'driverProfile'])
            ->findOrFail($request->validated('driver_id'));

        try {
            $this->assignments->assign($delivery, $driver, $request->user('web'));
        } catch (DeliveryWorkflowException $exception) {
            return back()->withErrors(['driver_id' => $exception->getMessage()]);
        }

        return back()->with('success', 'Driver assigned successfully.');
    }

    public function unassign(Request $request, Delivery $delivery): RedirectResponse
    {
        Gate::authorize('unassignDriver', $delivery);

        try {
            $this->assignments->unassign($delivery, $request->user('web'));
        } catch (DeliveryWorkflowException $exception) {
            return back()->withErrors(['driver_id' => $exception->getMessage()]);
        }

        return back()->with('success', 'Driver unassigned successfully.');
    }

    public function cancel(
        PortalCancelDeliveryRequest $request,
        Delivery $delivery
    ): RedirectResponse {
        try {
            $this->deliveries->cancel(
                $delivery,
                $request->user('web'),
                $request->validated('note')
            );
        } catch (DeliveryWorkflowException $exception) {
            return back()->withErrors(['delivery' => $exception->getMessage()]);
        }

        return redirect()
            ->route('portal.deliveries.show', $delivery)
            ->with('success', 'Delivery cancelled successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(User $user, int|string|null $businessId = null): array
    {
        $businessIds = $user->isSuperAdmin()
            ? $this->activeBusinesses()->pluck('id')
            : collect([$user->business_id])->filter();

        if ($businessId !== null) {
            $businessIds = $businessIds->filter(
                fn (mixed $id): bool => (string) $id === (string) $businessId
            );
        }

        return [
            'businesses' => $user->isSuperAdmin() ? $this->activeBusinesses() : collect(),
            'branches' => BusinessBranch::query()
                ->whereIn('business_id', $businessIds)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(),
            'customers' => Customer::query()
                ->whereIn('business_id', $businessIds)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(),
            'addresses' => CustomerAddress::query()
                ->whereIn('business_id', $businessIds)
                ->orderByDesc('is_default')
                ->orderBy('label')
                ->get(),
            'paymentMethods' => DeliveryManagementService::PAYMENT_METHODS,
        ];
    }

    private function activeBusinesses()
    {
        return Business::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }

    private function filterDrivers(User $user, int|string|null $businessId)
    {
        return User::query()
            ->whereHas('role', fn (Builder $query) => $query->where('name', 'driver'))
            ->when(
                $user->isSuperAdmin(),
                fn (Builder $query) => $query->when(
                    $businessId,
                    fn (Builder $query, int|string $id) => $query->where('business_id', $id)
                ),
                fn (Builder $query) => $query->where('business_id', $user->business_id)
            )
            ->orderBy('name')
            ->get();
    }
}
