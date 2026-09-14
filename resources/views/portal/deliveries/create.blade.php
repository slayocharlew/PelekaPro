@extends('layouts.portal')

@section('title', 'Create delivery')

@section('content')
    <div class="portal-page-heading portal-page-heading--compact">
        <div>
            <a class="portal-back-link" href="{{ route('portal.deliveries.index') }}">← Back to deliveries</a>
            <h1>Create delivery</h1>
        </div>
        <a class="portal-button portal-button--secondary" href="{{ route('portal.delivery-requests.create') }}">Request customer details instead</a>
    </div>

    <form class="portal-form portal-form--delivery" method="POST" action="{{ route('portal.deliveries.store') }}" data-delivery-form data-submitting-form>
        @csrf
        @include('portal.deliveries.partials.form', ['submitLabel' => 'Create delivery'])
    </form>
@endsection
