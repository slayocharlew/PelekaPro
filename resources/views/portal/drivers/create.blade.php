@extends('layouts.portal')

@section('title', 'Register driver')

@section('content')
    <div class="portal-page-heading portal-page-heading--compact">
        <div>
            <a class="portal-back-link" href="{{ route('portal.drivers.index') }}">← Back to drivers</a>
            <h1>Register driver</h1>
        </div>
    </div>

    @if ($errors->any())
        <div class="portal-alert portal-alert--error" role="alert">
            <strong>Please correct the highlighted information.</strong>
            @error('driver') <p>{{ $message }}</p> @enderror
        </div>
    @endif

    <form class="portal-form portal-form--delivery" method="POST" action="{{ route('portal.drivers.store') }}" data-submitting-form>
        @csrf

        <section class="portal-card portal-form-section">
            <div class="portal-card__header">
                <div><h2>Driver login</h2></div>
            </div>
            <div class="portal-form-grid">
                <div class="portal-field">
                    <label for="driver-name">Full name <span aria-hidden="true">*</span></label>
                    <input id="driver-name" name="name" type="text" maxlength="255" autocomplete="name" value="{{ old('name') }}" required>
                    @error('name') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="driver-phone">Phone <span aria-hidden="true">*</span></label>
                    <input id="driver-phone" name="phone" type="tel" maxlength="30" autocomplete="tel" value="{{ old('phone') }}" required>
                    @error('phone') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="driver-email">Email (optional)</label>
                    <input id="driver-email" name="email" type="email" maxlength="255" autocomplete="email" value="{{ old('email') }}" placeholder="Optional">
                    @error('email') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="driver-password">Initial password <span aria-hidden="true">*</span></label>
                    <input id="driver-password" name="password" type="password" minlength="8" autocomplete="new-password" required>
                    <p class="portal-field__hint">Use at least eight characters with letters and numbers.</p>
                    @error('password') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="driver-password-confirmation">Confirm password <span aria-hidden="true">*</span></label>
                    <input id="driver-password-confirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required>
                </div>
                <div class="portal-field">
                    <label for="driver-branch">Branch</label>
                    <select id="driver-branch" name="branch_id">
                        <option value="">No branch assigned</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) old('branch_id', $defaultBranchId) === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="portal-card portal-form-section">
            <div class="portal-card__header">
                <div><h2>Vehicle information</h2></div>
            </div>
            <div class="portal-form-grid">
                <div class="portal-field">
                    <label for="vehicle-type">Vehicle type</label>
                    <select id="vehicle-type" name="vehicle_type">
                        <option value="">Not provided</option>
                        @foreach ($vehicleTypes as $vehicleType)
                            <option value="{{ $vehicleType }}" @selected(old('vehicle_type') === $vehicleType)>{{ str($vehicleType)->title() }}</option>
                        @endforeach
                    </select>
                    @error('vehicle_type') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="vehicle-number">Vehicle registration number</label>
                    <input id="vehicle-number" name="vehicle_number" type="text" maxlength="255" value="{{ old('vehicle_number') }}">
                    @error('vehicle_number') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="license-number">Driver licence number</label>
                    <input id="license-number" name="license_number" type="text" maxlength="255" value="{{ old('license_number') }}">
                    @error('license_number') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <div class="portal-form-actions">
            <a class="portal-button portal-button--quiet" href="{{ route('portal.drivers.index') }}">Cancel</a>
            <button class="portal-button portal-button--primary" type="submit" data-submit-label="Registering…">Register driver</button>
        </div>
    </form>
@endsection
