@extends('layouts.portal')

@section('title', 'Customer requests')

@section('content')
    <div class="portal-page-heading">
        <div>
            <h1>Customer requests</h1>
        </div>
        <a class="portal-button portal-button--primary" href="{{ route('portal.delivery-requests.create') }}">Generate request link</a>
    </div>

    <form class="portal-filter-card" method="GET" action="{{ route('portal.delivery-requests.index') }}">
        <div class="portal-field portal-field--search">
            <label for="search">Search</label>
            <input id="search" name="search" type="search" value="{{ $filters['search'] ?? '' }}" placeholder="Customer name or phone">
        </div>

        @if (auth('web')->user()->isSuperAdmin())
            <div class="portal-field">
                <label for="business_id">Business</label>
                <select id="business_id" name="business_id">
                    <option value="">All businesses</option>
                    @foreach ($businesses as $business)
                        <option value="{{ $business->id }}" @selected((string) ($filters['business_id'] ?? '') === (string) $business->id)>{{ $business->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="portal-field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->title() }}</option>
                @endforeach
            </select>
        </div>

        <div class="portal-filter-card__actions">
            <button class="portal-button portal-button--secondary" type="submit">Apply filters</button>
            <a class="portal-button portal-button--quiet" href="{{ route('portal.delivery-requests.index') }}">Clear</a>
        </div>
    </form>

    <section class="portal-card" aria-labelledby="request-results-heading">
        <div class="portal-card__header">
            <div>
                <h2 id="request-results-heading">Request records</h2>
                <p>{{ number_format($deliveryRequests->total()) }} {{ str('request')->plural($deliveryRequests->total()) }}</p>
            </div>
        </div>

        @if ($deliveryRequests->isEmpty())
            <div class="portal-empty">
                <h3>No customer requests found</h3>
                <p>Generate a secure link when a customer should provide the delivery details.</p>
                <a class="portal-button portal-button--primary" href="{{ route('portal.delivery-requests.create') }}">Generate request link</a>
            </div>
        @else
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th scope="col">Request</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Status</th>
                            <th scope="col">Created</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deliveryRequests as $deliveryRequest)
                            <tr>
                                <td data-label="Request">
                                    <a class="portal-table__primary" href="{{ route('portal.delivery-requests.show', $deliveryRequest) }}">Request #{{ $deliveryRequest->id }}</a>
                                    @if (auth('web')->user()->isSuperAdmin())
                                        <small>{{ $deliveryRequest->business?->name }}</small>
                                    @endif
                                </td>
                                <td data-label="Customer">
                                    <strong>{{ $deliveryRequest->customer_name ?: 'Waiting for customer' }}</strong>
                                    <span>{{ $deliveryRequest->customer_phone ?: '—' }}</span>
                                </td>
                                <td data-label="Status">@include('portal.partials.status-badge', ['status' => $deliveryRequest->effectiveStatus()])</td>
                                <td data-label="Created"><time datetime="{{ $deliveryRequest->created_at?->toISOString() }}">{{ $deliveryRequest->created_at?->format('d M Y, H:i') }}</time></td>
                                <td class="portal-table__actions">
                                    <a class="portal-button portal-button--quiet portal-button--small" href="{{ route('portal.delivery-requests.show', $deliveryRequest) }}">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($deliveryRequests->hasPages())
                <div class="portal-pagination">{{ $deliveryRequests->links() }}</div>
            @endif
        @endif
    </section>
@endsection
