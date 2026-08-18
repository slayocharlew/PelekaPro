@extends('layouts.portal')

@section('title', 'Businesses')

@section('content')
    <div class="portal-page-heading">
        <div>
            <h1>Businesses</h1>
        </div>
        <a class="portal-button portal-button--primary" href="{{ route('portal.businesses.create') }}">Register business</a>
    </div>

    <section class="portal-card" aria-labelledby="business-results-heading">
        <div class="portal-card__header">
            <div>
                <h2 id="business-results-heading">Registered businesses</h2>
                <p>{{ number_format($businesses->total()) }} {{ str('business')->plural($businesses->total()) }}</p>
            </div>
        </div>

        @if ($businesses->isEmpty())
            <div class="portal-empty">
                <h3>No businesses registered</h3>
                <p>Create the business, its main pickup branch, and owner account together.</p>
                <a class="portal-button portal-button--primary" href="{{ route('portal.businesses.create') }}">Register first business</a>
            </div>
        @else
            <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th scope="col">Business</th>
                            <th scope="col">Owner</th>
                            <th scope="col">Main branch</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($businesses as $business)
                            @php($owner = $business->users->first())
                            <tr>
                                <td data-label="Business">
                                    <a class="portal-table__primary" href="{{ route('portal.businesses.show', $business) }}">{{ $business->name }}</a>
                                    <small>{{ $business->business_code }}</small>
                                </td>
                                <td data-label="Owner">
                                    <strong>{{ $owner?->name ?? 'No active owner' }}</strong>
                                    <span>{{ $owner?->phone ?? '—' }}</span>
                                </td>
                                <td data-label="Main branch">
                                    <strong>{{ $owner?->branch?->name ?? $business->branches->first()?->name ?? '—' }}</strong>
                                    <span>{{ $business->branches_count }} {{ str('branch')->plural($business->branches_count) }}</span>
                                </td>
                                <td data-label="Status">@include('portal.partials.status-badge', ['status' => $business->status])</td>
                                <td class="portal-table__actions">
                                    <a class="portal-button portal-button--quiet portal-button--small" href="{{ route('portal.businesses.show', $business) }}">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($businesses->hasPages())
                <div class="portal-pagination">{{ $businesses->links() }}</div>
            @endif
        @endif
    </section>
@endsection
