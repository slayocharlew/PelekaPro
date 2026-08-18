<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\PortalStoreBusinessRequest;
use App\Models\Business;
use App\Services\BusinessOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BusinessController extends Controller
{
    public function __construct(
        private readonly BusinessOnboardingService $onboarding,
    ) {}

    public function index(): View
    {
        Gate::authorize('viewAny', Business::class);

        $businesses = Business::query()
            ->withCount('branches')
            ->with([
                'branches',
                'users' => fn ($query) => $query
                    ->whereHas('role', fn ($query) => $query->where('name', 'business_owner'))
                    ->with(['role', 'branch']),
            ])
            ->latest()
            ->paginate(15);

        return view('portal.businesses.index', compact('businesses'));
    }

    public function create(): View
    {
        Gate::authorize('create', Business::class);

        return view('portal.businesses.create');
    }

    public function store(PortalStoreBusinessRequest $request): RedirectResponse
    {
        $business = $this->onboarding->onboard($request->validated());

        return redirect()
            ->route('portal.businesses.show', $business)
            ->with('success', 'Business, main branch, and owner account created successfully.');
    }

    public function show(Business $business): View
    {
        Gate::authorize('view', $business);
        $business->load(['branches', 'users.role', 'users.branch']);

        return view('portal.businesses.show', [
            'business' => $business,
            'owners' => $business->users->filter(fn ($user): bool => $user->isBusinessOwner()),
        ]);
    }
}
