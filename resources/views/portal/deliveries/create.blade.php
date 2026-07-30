@extends('layouts.portal')

@section('title', 'Create delivery')

@section('content')
    <div class="portal-page-heading portal-page-heading--compact">
        <div>
            <a class="portal-back-link" href="{{ route('portal.deliveries.index') }}">← Back to deliveries</a>
            <p class="portal-eyebrow">New order</p>
            <h1>Create delivery</h1>
            <p>Enter the customer, location, items, and business-authorized payment details.</p>
        </div>
    </div>

    <form class="portal-form portal-form--delivery" method="POST" action="{{ route('portal.deliveries.store') }}" data-delivery-form data-submitting-form>
        @csrf
        @include('portal.deliveries.partials.form', ['submitLabel' => 'Create delivery'])
    </form>
@endsection
