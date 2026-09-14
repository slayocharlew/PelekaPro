@extends('layouts.portal')

@section('title', 'Request customer details')

@section('content')
    <div class="portal-page-heading portal-page-heading--compact">
        <div>
            <a class="portal-back-link" href="{{ route('portal.delivery-requests.index') }}">← Back to requests</a>
            <h1>Request customer details</h1>
        </div>
        <a class="portal-button portal-button--quiet" href="{{ route('portal.deliveries.create') }}">Create manually instead</a>
    </div>

    <form class="portal-form" method="POST" action="{{ route('portal.delivery-requests.store') }}" data-submitting-form>
        @csrf
        <section class="portal-card portal-form-section">
            <div class="portal-card__header">
                <div>
                    <h2>Generate secure form link</h2>
                    <p>The link lasts 24 hours and is shown only once. The official delivery is created after review.</p>
                </div>
            </div>
            <div class="portal-form-grid">
                @if (auth('web')->user()->isSuperAdmin())
                    <div class="portal-field">
                        <label for="business_id">Business <span aria-hidden="true">*</span></label>
                        <select id="business_id" name="business_id" required>
                            <option value="">Select a business</option>
                            @foreach ($businesses as $business)
                                <option value="{{ $business->id }}" @selected((string) old('business_id') === (string) $business->id)>{{ $business->name }}</option>
                            @endforeach
                        </select>
                        @error('business_id') <p class="portal-field__error">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div class="portal-field">
                        <label>Business</label>
                        <input type="text" value="{{ auth('web')->user()->business?->name }}" disabled>
                    </div>
                @endif
            </div>
        </section>

        <div class="portal-form-actions">
            <a class="portal-button portal-button--quiet" href="{{ route('portal.delivery-requests.index') }}">Cancel</a>
            <button class="portal-button portal-button--primary" type="submit" data-submit-label="Generating…">Generate request link</button>
        </div>
    </form>
@endsection
