@extends('layouts.portal')

@section('title', 'Drivers')

@section('content')
    <div class="portal-page-heading">
        <div>
            <h1>Drivers</h1>
        </div>
        <a class="portal-button portal-button--primary" href="{{ route('portal.drivers.create') }}">Register driver</a>
    </div>

    <form class="portal-filter-card" method="GET" action="{{ route('portal.drivers.index') }}">
        <div class="portal-field portal-field--search">
            <label for="driver-search">Search</label>
            <input id="driver-search" name="search" type="search" value="{{ request('search') }}" placeholder="Name, phone, email or vehicle">
        </div>
        <div class="portal-field">
            <label for="driver-status">Work status</label>
            <select id="driver-status" name="status">
                <option value="">All statuses</option>
                @foreach ($profileStatuses as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>
                @endforeach
            </select>
        </div>
        <div class="portal-field">
            <label for="driver-branch">Branch</label>
            <select id="driver-branch" name="branch">
                <option value="">All branches</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((string) request('branch') === (string) $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="portal-filter-card__actions">
            <button class="portal-button portal-button--secondary" type="submit">Apply filters</button>
            <a class="portal-button portal-button--quiet" href="{{ route('portal.drivers.index') }}">Clear</a>
        </div>
    </form>

    <section class="portal-card" aria-labelledby="driver-results-heading">
        <div class="portal-card__header">
            <div>
                <h2 id="driver-results-heading">Registered drivers</h2>
                <p>{{ number_format($drivers->total()) }} {{ str('driver')->plural($drivers->total()) }}</p>
            </div>
        </div>

        @if ($drivers->isEmpty())
            <div class="portal-empty">
                <h3>No drivers found</h3>
                <p>Register a driver account so deliveries can be assigned to them.</p>
                <a class="portal-button portal-button--primary" href="{{ route('portal.drivers.create') }}">Register first driver</a>
            </div>
        @else
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th scope="col">Driver</th>
                            <th scope="col">Branch</th>
                            <th scope="col">Vehicle</th>
                            <th scope="col">Account</th>
                            <th scope="col">Work status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($drivers as $driver)
                            <tr>
                                <td data-label="Driver">
                                    <strong>{{ $driver->name }}</strong>
                                    <span>{{ $driver->phone }}</span>
                                    @if ($driver->email)<small>{{ $driver->email }}</small>@endif
                                </td>
                                <td data-label="Branch">{{ $driver->driverProfile?->branch?->name ?? $driver->branch?->name ?? 'Not assigned' }}</td>
                                <td data-label="Vehicle">
                                    <strong>{{ $driver->driverProfile?->vehicle_type ? str($driver->driverProfile->vehicle_type)->title() : 'Not provided' }}</strong>
                                    <span>{{ $driver->driverProfile?->vehicle_number ?? '—' }}</span>
                                </td>
                                <td data-label="Account">@include('portal.partials.status-badge', ['status' => $driver->status])</td>
                                <td data-label="Work status">@include('portal.partials.status-badge', ['status' => $driver->driverProfile?->current_status ?? 'unavailable'])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($drivers->hasPages())
                <div class="portal-pagination">{{ $drivers->links() }}</div>
            @endif
        @endif
    </section>
@endsection
