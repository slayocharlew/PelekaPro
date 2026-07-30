@extends('layouts.portal')

@section('title', 'Deliveries')

@section('content')
    <div class="portal-page-heading">
        <div>
            <p class="portal-eyebrow">Operations</p>
            <h1>Deliveries</h1>
            <p>Manage orders, driver assignment, and customer tracking access.</p>
        </div>
        <a class="portal-button portal-button--primary" href="{{ route('portal.deliveries.create') }}">Create delivery</a>
    </div>

    <form class="portal-filter-card" method="GET" action="{{ route('portal.deliveries.index') }}">
        <div class="portal-field portal-field--search">
            <label for="search">Search</label>
            <input id="search" name="search" type="search" value="{{ $filters['search'] ?? '' }}" placeholder="Delivery number, tracking code, customer">
        </div>

        @if (auth('web')->user()->isSuperAdmin())
            <div class="portal-field">
                <label for="business_id">Business</label>
                <select id="business_id" name="business_id">
                    <option value="">All businesses</option>
                    @foreach ($businesses as $business)
                        <option value="{{ $business->id }}" @selected((string) ($filters['business_id'] ?? '') === (string) $business->id)>
                            {{ $business->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="portal-field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                        {{ str($status)->replace('_', ' ')->title() }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="portal-field">
            <label for="assigned_driver_id">Driver</label>
            <select id="assigned_driver_id" name="assigned_driver_id">
                <option value="">All drivers</option>
                @foreach ($drivers as $driver)
                    <option value="{{ $driver->id }}" @selected((string) ($filters['assigned_driver_id'] ?? '') === (string) $driver->id)>
                        {{ $driver->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="portal-filter-card__actions">
            <button class="portal-button portal-button--secondary" type="submit">Apply filters</button>
            <a class="portal-button portal-button--quiet" href="{{ route('portal.deliveries.index') }}">Clear</a>
        </div>
    </form>

    <section class="portal-card" aria-labelledby="delivery-results-heading">
        <div class="portal-card__header">
            <div>
                <h2 id="delivery-results-heading">Delivery records</h2>
                <p>{{ number_format($deliveries->total()) }} {{ str('delivery')->plural($deliveries->total()) }}</p>
            </div>
        </div>

        @if ($deliveries->isEmpty())
            <div class="portal-empty">
                <div class="portal-empty__icon" aria-hidden="true">↗</div>
                <h3>No deliveries found</h3>
                <p>Adjust the filters or create the first delivery for this business.</p>
                <a class="portal-button portal-button--primary" href="{{ route('portal.deliveries.create') }}">Create delivery</a>
            </div>
        @else
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th scope="col">Delivery</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Status</th>
                            <th scope="col">Driver</th>
                            <th scope="col">Payment</th>
                            <th scope="col">Created</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deliveries as $delivery)
                            <tr>
                                <td data-label="Delivery">
                                    <a class="portal-table__primary" href="{{ route('portal.deliveries.show', $delivery) }}">
                                        {{ $delivery->delivery_number }}
                                    </a>
                                    <span>{{ $delivery->tracking_code }}</span>
                                    @if (auth('web')->user()->isSuperAdmin())
                                        <small>{{ $delivery->business?->name }}</small>
                                    @endif
                                </td>
                                <td data-label="Customer">
                                    <strong>{{ $delivery->customer?->name ?? 'Not available' }}</strong>
                                    <span>{{ $delivery->customer?->phone ?? '—' }}</span>
                                </td>
                                <td data-label="Status">@include('portal.partials.status-badge', ['status' => $delivery->status])</td>
                                <td data-label="Driver">{{ $delivery->assignedDriver?->name ?? 'Unassigned' }}</td>
                                <td data-label="Payment">
                                    <strong>TZS {{ number_format((float) ($delivery->payment?->expected_amount ?? $delivery->amount_to_collect), 2) }}</strong>
                                    <span>{{ str($delivery->payment?->payment_status ?? 'pending')->replace('_', ' ')->title() }}</span>
                                </td>
                                <td data-label="Created">
                                    <time datetime="{{ $delivery->created_at?->toISOString() }}">{{ $delivery->created_at?->format('d M Y') }}</time>
                                </td>
                                <td class="portal-table__actions">
                                    <a class="portal-button portal-button--quiet portal-button--small" href="{{ route('portal.deliveries.show', $delivery) }}">View</a>
                                    @if (in_array($delivery->getKey(), $editableDeliveryIds, true))
                                        <a class="portal-button portal-button--quiet portal-button--small" href="{{ route('portal.deliveries.edit', $delivery) }}">Edit</a>
                                    @endif
                                    @if (in_array($delivery->getKey(), $assignableDeliveryIds, true))
                                        @if ($delivery->assigned_driver_id)
                                            <form method="POST" action="{{ route('portal.deliveries.unassign', $delivery) }}" data-confirm="Remove this driver from the delivery?" data-submitting-form>
                                                @csrf
                                                @method('DELETE')
                                                <button class="portal-button portal-button--quiet portal-button--small" type="submit" data-submit-label="Removing…">Unassign</button>
                                            </form>
                                        @else
                                            <a class="portal-button portal-button--quiet portal-button--small" href="{{ route('portal.deliveries.show', $delivery) }}#driver-assignment">Assign</a>
                                        @endif
                                    @endif
                                    @if (in_array($delivery->getKey(), $cancellableDeliveryIds, true))
                                        <form method="POST" action="{{ route('portal.deliveries.cancel', $delivery) }}" data-confirm="Cancel this delivery? This action stops any active tracking session." data-submitting-form>
                                            @csrf
                                            <button class="portal-button portal-button--quiet portal-button--small" type="submit" data-submit-label="Cancelling…">Cancel</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($deliveries->hasPages())
                <div class="portal-pagination">{{ $deliveries->links() }}</div>
            @endif
        @endif
    </section>
@endsection
