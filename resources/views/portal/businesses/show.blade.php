@extends('layouts.portal')

@section('title', $business->name)

@section('content')
    <div class="portal-page-heading">
        <div>
            <a class="portal-back-link" href="{{ route('portal.businesses.index') }}">← Back to businesses</a>
            <div class="portal-heading-line">
                <h1>{{ $business->name }}</h1>
                @include('portal.partials.status-badge', ['status' => $business->status])
            </div>
            <p>{{ $business->business_code }}</p>
        </div>
    </div>

    <div class="portal-detail-grid">
        <div class="portal-detail-main">
            <section class="portal-card">
                <div class="portal-card__header"><div><h2>Business information</h2></div></div>
                <dl class="portal-detail-list">
                    <div><dt>Phone</dt><dd>{{ $business->phone ?: '—' }}</dd></div>
                    <div><dt>Email</dt><dd>{{ $business->email ?: '—' }}</dd></div>
                    <div><dt>Business type</dt><dd>{{ $business->business_type ?: '—' }}</dd></div>
                    <div><dt>TIN number</dt><dd>{{ $business->tin_number ?: '—' }}</dd></div>
                    <div class="portal-detail-list__wide"><dt>Address</dt><dd>{{ $business->address ?: '—' }}</dd></div>
                </dl>
            </section>

            <section class="portal-card">
                <div class="portal-card__header"><div><h2>Branches and pickup locations</h2></div></div>
                <div class="portal-items-summary">
                    @foreach ($business->branches as $branch)
                        <article>
                            <div>
                                <strong>{{ $branch->name }}</strong>
                                <p>{{ $branch->pickupAddress() ?: 'No written address' }}</p>
                            </div>
                            <span>{{ $branch->phone ?: 'No phone' }}</span>
                            <span>
                                @if ($branch->latitude !== null && $branch->longitude !== null)
                                    <a href="https://www.google.com/maps/search/?api=1&amp;query={{ $branch->latitude }},{{ $branch->longitude }}" target="_blank" rel="noopener noreferrer">View map</a>
                                @else
                                    Location missing
                                @endif
                            </span>
                        </article>
                    @endforeach
                </div>
            </section>
        </div>

        <aside class="portal-detail-sidebar">
            <section class="portal-card">
                <div class="portal-card__header"><div><h2>Business owner</h2></div></div>
                <dl class="portal-summary-list">
                    @forelse ($owners as $owner)
                        <div><dt>Name</dt><dd>{{ $owner->name }}</dd></div>
                        <div><dt>Phone</dt><dd>{{ $owner->phone ?: '—' }}</dd></div>
                        <div><dt>Email</dt><dd>{{ $owner->email ?: '—' }}</dd></div>
                        <div><dt>Main branch</dt><dd>{{ $owner->branch?->name ?: '—' }}</dd></div>
                    @empty
                        <div><dt>Owner</dt><dd>No active owner account</dd></div>
                    @endforelse
                </dl>
            </section>
        </aside>
    </div>
@endsection
