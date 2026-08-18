@extends('layouts.portal')

@section('title', 'Register business')

@section('content')
    <div class="portal-page-heading portal-page-heading--compact">
        <div>
            <a class="portal-back-link" href="{{ route('portal.businesses.index') }}">← Back to businesses</a>
            <h1>Register business</h1>
        </div>
    </div>

    @if ($errors->any())
        <div class="portal-alert portal-alert--error" role="alert">
            <strong>Please correct the highlighted information.</strong>
        </div>
    @endif

    <form
        class="portal-form portal-form--delivery"
        method="POST"
        action="{{ route('portal.businesses.store') }}"
        data-business-onboarding
        data-submitting-form
    >
        @csrf

        <section class="portal-card portal-form-section">
            <div class="portal-card__header">
                <div><h2>Business information</h2></div>
            </div>
            <div class="portal-form-grid">
                <div class="portal-field">
                    <label for="business_name">Business name <span aria-hidden="true">*</span></label>
                    <input id="business_name" name="business[name]" type="text" maxlength="255" value="{{ old('business.name') }}" required>
                    @error('business.name') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="business_type">Business type</label>
                    <input id="business_type" name="business[business_type]" type="text" maxlength="255" value="{{ old('business.business_type') }}" placeholder="For example: restaurant or retail shop">
                    @error('business.business_type') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="tin_number">TIN number (optional)</label>
                    <input id="tin_number" name="business[tin_number]" type="text" maxlength="255" value="{{ old('business.tin_number') }}">
                    @error('business.tin_number') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="business_phone">Business phone</label>
                    <input id="business_phone" name="business[phone]" type="tel" maxlength="30" value="{{ old('business.phone') }}">
                    @error('business.phone') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field portal-field--wide">
                    <label for="business_email">Business email (optional)</label>
                    <input id="business_email" name="business[email]" type="email" maxlength="255" value="{{ old('business.email') }}">
                    @error('business.email') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="portal-card portal-form-section">
            <div class="portal-card__header">
                <div>
                    <h2>Main pickup branch</h2>
                    <p>This saved location will fill delivery pickup information automatically.</p>
                </div>
            </div>
            <div class="portal-form-grid">
                <div class="portal-field">
                    <label for="branch_name">Branch name <span aria-hidden="true">*</span></label>
                    <input id="branch_name" name="branch[name]" type="text" maxlength="255" value="{{ old('branch.name', 'Main Branch') }}" required>
                    @error('branch.name') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="branch_phone">Pickup phone</label>
                    <input id="branch_phone" name="branch[phone]" type="tel" maxlength="30" value="{{ old('branch.phone') }}" placeholder="Uses business phone when empty">
                    @error('branch.phone') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="branch_region">Region</label>
                    <input id="branch_region" name="branch[region]" type="text" maxlength="255" value="{{ old('branch.region') }}">
                    @error('branch.region') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="branch_district">District</label>
                    <input id="branch_district" name="branch[district]" type="text" maxlength="255" value="{{ old('branch.district') }}">
                    @error('branch.district') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="branch_ward">Ward</label>
                    <input id="branch_ward" name="branch[ward]" type="text" maxlength="255" value="{{ old('branch.ward') }}">
                    @error('branch.ward') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="branch_street">Street</label>
                    <input id="branch_street" name="branch[street]" type="text" maxlength="255" value="{{ old('branch.street') }}">
                    @error('branch.street') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field portal-field--wide">
                    <label for="branch_address">Written pickup address <span aria-hidden="true">*</span></label>
                    <textarea id="branch_address" name="branch[address]" rows="3" maxlength="2000" required>{{ old('branch.address') }}</textarea>
                    @error('branch.address') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="portal-location-controls">
                <button class="portal-button portal-button--secondary" type="button" data-use-branch-current-location>Use my current location</button>
                <p id="branch-location-status" data-branch-location-status role="status" aria-live="polite">Tap the map or use the current location to place the main branch pin.</p>
            </div>
            <div class="portal-location-map" data-branch-location-map role="application" tabindex="0" aria-label="Select main branch location" aria-describedby="branch-location-status"></div>
            <input name="branch[latitude]" type="hidden" value="{{ old('branch.latitude') }}" data-branch-latitude>
            <input name="branch[longitude]" type="hidden" value="{{ old('branch.longitude') }}" data-branch-longitude>
            @error('branch.latitude') <p class="portal-field__error portal-location-error">{{ $message }}</p> @enderror
            @error('branch.longitude') <p class="portal-field__error portal-location-error">{{ $message }}</p> @enderror
        </section>

        <section class="portal-card portal-form-section">
            <div class="portal-card__header">
                <div>
                    <h2>Business owner account</h2>
                    <p>The owner can sign in with their email or phone. The password is stored only as a secure hash.</p>
                </div>
            </div>
            <div class="portal-form-grid">
                <div class="portal-field">
                    <label for="owner_name">Owner name <span aria-hidden="true">*</span></label>
                    <input id="owner_name" name="owner[name]" type="text" maxlength="255" autocomplete="name" value="{{ old('owner.name') }}" required>
                    @error('owner.name') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="owner_phone">Owner phone <span aria-hidden="true">*</span></label>
                    <input id="owner_phone" name="owner[phone]" type="tel" maxlength="30" autocomplete="tel" value="{{ old('owner.phone') }}" required>
                    @error('owner.phone') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field portal-field--wide">
                    <label for="owner_email">Owner email <span aria-hidden="true">*</span></label>
                    <input id="owner_email" name="owner[email]" type="email" maxlength="255" autocomplete="email" value="{{ old('owner.email') }}" required>
                    @error('owner.email') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="owner_password">Initial password <span aria-hidden="true">*</span></label>
                    <input id="owner_password" name="owner[password]" type="password" minlength="8" autocomplete="new-password" required>
                    @error('owner.password') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="owner_password_confirmation">Confirm initial password <span aria-hidden="true">*</span></label>
                    <input id="owner_password_confirmation" name="owner[password_confirmation]" type="password" minlength="8" autocomplete="new-password" required>
                </div>
            </div>
        </section>

        <div class="portal-form-actions">
            <a class="portal-button portal-button--quiet" href="{{ route('portal.businesses.index') }}">Cancel</a>
            <button class="portal-button portal-button--primary" type="submit" data-submit-label="Registering…">Register business</button>
        </div>
    </form>
@endsection
