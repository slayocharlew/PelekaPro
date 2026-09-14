@extends('layouts.portal')

@section('title', 'Business settings')

@section('content')
    <div class="portal-page-heading portal-page-heading--compact">
        <div>
            <h1>Business settings</h1>
            <p>{{ $business->name }}</p>
        </div>
    </div>

    @if ($errors->any())
        <div class="portal-alert portal-alert--error" role="alert">
            <strong>Please correct the highlighted shop information.</strong>
        </div>
    @endif

    <form
        class="portal-form portal-form--delivery"
        method="POST"
        action="{{ route('portal.settings.shop-location.update') }}"
        data-business-settings
        data-branch-location-form
        data-location-required="true"
        data-location-required-message="Select your exact main shop location before saving."
        data-submitting-form
    >
        @csrf
        @method('PUT')

        <section class="portal-card portal-form-section">
            <div class="portal-card__header">
                <div>
                    <h2>Main shop and pickup location</h2>
                    <p>This location will automatically become the pickup point when you select this branch on a delivery.</p>
                </div>
            </div>

            @if ($branch->latitude === null || $branch->longitude === null)
                <div class="portal-alert portal-alert--error" role="status">
                    Your shop map location is not set. Place the pin before creating deliveries that use this branch.
                </div>
            @endif

            <div class="portal-form-grid">
                <div class="portal-field">
                    <label for="branch_name">Branch name <span aria-hidden="true">*</span></label>
                    <input id="branch_name" name="branch[name]" type="text" maxlength="255" value="{{ old('branch.name', $branch->name) }}" required>
                    @error('branch.name') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="branch_phone">Pickup phone</label>
                    <input id="branch_phone" name="branch[phone]" type="tel" maxlength="30" value="{{ old('branch.phone', $branch->phone) }}" placeholder="Uses business phone when empty">
                    @error('branch.phone') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="branch_region">Region</label>
                    <input id="branch_region" name="branch[region]" type="text" maxlength="255" value="{{ old('branch.region', $branch->region) }}">
                    @error('branch.region') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="branch_district">District</label>
                    <input id="branch_district" name="branch[district]" type="text" maxlength="255" value="{{ old('branch.district', $branch->district) }}">
                    @error('branch.district') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="branch_ward">Ward</label>
                    <input id="branch_ward" name="branch[ward]" type="text" maxlength="255" value="{{ old('branch.ward', $branch->ward) }}">
                    @error('branch.ward') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="branch_street">Street</label>
                    <input id="branch_street" name="branch[street]" type="text" maxlength="255" value="{{ old('branch.street', $branch->street) }}">
                    @error('branch.street') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field portal-field--wide">
                    <label for="branch_address">Written pickup address <span aria-hidden="true">*</span></label>
                    <textarea id="branch_address" name="branch[address]" rows="3" maxlength="2000" required>{{ old('branch.address', $branch->address) }}</textarea>
                    @error('branch.address') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="portal-location-controls">
                <button class="portal-button portal-button--secondary" type="button" data-use-branch-current-location>Use my current location</button>
                <p id="branch-location-status" data-branch-location-status role="status" aria-live="polite">
                    @if ($branch->latitude !== null && $branch->longitude !== null)
                        Your saved shop pin is shown below. Drag it or tap the map to correct it.
                    @else
                        Tap the map or use your current location to place the shop pin.
                    @endif
                </p>
            </div>
            <div class="portal-location-map" data-branch-location-map role="application" tabindex="0" aria-label="Select main shop location" aria-describedby="branch-location-status"></div>
            <input name="branch[latitude]" type="hidden" value="{{ old('branch.latitude', $branch->latitude) }}" data-branch-latitude>
            <input name="branch[longitude]" type="hidden" value="{{ old('branch.longitude', $branch->longitude) }}" data-branch-longitude>
            @error('branch.latitude') <p class="portal-field__error portal-location-error">{{ $message }}</p> @enderror
            @error('branch.longitude') <p class="portal-field__error portal-location-error">{{ $message }}</p> @enderror
        </section>

        <div class="portal-form-actions">
            <a class="portal-button portal-button--quiet" href="{{ route('portal.deliveries.index') }}">Cancel</a>
            <button class="portal-button portal-button--primary" type="submit" data-submit-label="Saving…">Save shop location</button>
        </div>
    </form>
@endsection
