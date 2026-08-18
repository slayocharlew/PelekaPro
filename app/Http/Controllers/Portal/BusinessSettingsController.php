<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\PortalUpdateShopLocationRequest;
use App\Models\User;
use App\Services\BusinessSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BusinessSettingsController extends Controller
{
    public function __construct(
        private readonly BusinessSettingsService $settings,
    ) {}

    public function edit(Request $request): View
    {
        $owner = $this->owner($request);

        return view('portal.settings.edit', [
            'business' => $owner->business,
            'branch' => $this->settings->branchFor($owner),
        ]);
    }

    public function update(
        PortalUpdateShopLocationRequest $request
    ): RedirectResponse {
        $this->settings->updateShopLocation(
            $request->user('web'),
            $request->validated()
        );

        return redirect()
            ->route('portal.settings.edit')
            ->with('success', 'Main shop location updated successfully.');
    }

    private function owner(Request $request): User
    {
        $user = $request->user('web');

        abort_unless(
            $user instanceof User && $user->isBusinessOwner() && $user->business_id !== null,
            403
        );

        return $user;
    }
}
