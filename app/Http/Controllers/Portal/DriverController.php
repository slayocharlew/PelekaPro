<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\PortalStoreDriverRequest;
use App\Models\BusinessBranch;
use App\Models\User;
use App\Services\DriverRegistrationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DriverController extends Controller
{
    public function __construct(
        private readonly DriverRegistrationService $registration,
    ) {}

    public function index(Request $request): View
    {
        $manager = $this->manager($request);
        $businessId = (int) $manager->business_id;
        $search = trim($request->string('search')->toString());
        $status = $request->string('status')->toString();
        $branchId = $request->integer('branch');

        $drivers = User::query()
            ->where('business_id', $businessId)
            ->whereHas('role', fn (Builder $query) => $query->where('name', 'driver'))
            ->with(['branch', 'driverProfile.branch'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('driverProfile', function (Builder $profileQuery) use ($search): void {
                            $profileQuery
                                ->where('vehicle_number', 'like', "%{$search}%")
                                ->orWhere('license_number', 'like', "%{$search}%");
                        });
                });
            })
            ->when(
                in_array($status, DriverRegistrationService::PROFILE_STATUSES, true),
                fn (Builder $query) => $query->whereHas(
                    'driverProfile',
                    fn (Builder $profileQuery) => $profileQuery->where('current_status', $status)
                )
            )
            ->when(
                $branchId > 0,
                fn (Builder $query) => $query->whereHas(
                    'driverProfile',
                    fn (Builder $profileQuery) => $profileQuery
                        ->where('business_id', $businessId)
                        ->where('branch_id', $branchId)
                )
            )
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('portal.drivers.index', [
            'drivers' => $drivers,
            'branches' => $this->activeBranches($businessId),
            'profileStatuses' => DriverRegistrationService::PROFILE_STATUSES,
        ]);
    }

    public function create(Request $request): View
    {
        $manager = $this->manager($request);

        return view('portal.drivers.create', [
            'branches' => $this->activeBranches((int) $manager->business_id),
            'vehicleTypes' => DriverRegistrationService::VEHICLE_TYPES,
            'defaultBranchId' => $manager->branch_id,
        ]);
    }

    public function store(PortalStoreDriverRequest $request): RedirectResponse
    {
        $driver = $this->registration->register(
            $request->user('web'),
            $request->validated()
        );

        return redirect()
            ->route('portal.drivers.index')
            ->with('success', "{$driver->name}'s driver account was created successfully.");
    }

    private function manager(Request $request): User
    {
        $user = $request->user('web');

        abort_unless(
            $user instanceof User
                && ($user->isBusinessOwner() || $user->isBusinessAdmin())
                && $user->business_id !== null,
            403
        );

        return $user;
    }

    /**
     * @return Collection<int, BusinessBranch>
     */
    private function activeBranches(int $businessId): Collection
    {
        return BusinessBranch::query()
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }
}
