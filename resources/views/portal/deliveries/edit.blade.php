@extends('layouts.portal')

@section('title', 'Edit '.$delivery->delivery_number)

@section('content')
    <div class="portal-page-heading portal-page-heading--compact">
        <div>
            <a class="portal-back-link" href="{{ route('portal.deliveries.show', $delivery) }}">← Back to delivery</a>
            <p class="portal-eyebrow">Edit order</p>
            <h1>{{ $delivery->delivery_number }}</h1>
            <p>Changes are allowed only before the delivery starts. Driver assignment is managed separately.</p>
        </div>
    </div>

    <form class="portal-form portal-form--delivery" method="POST" action="{{ route('portal.deliveries.update', $delivery) }}" data-delivery-form data-submitting-form>
        @csrf
        @method('PUT')
        @include('portal.deliveries.partials.form', ['submitLabel' => 'Save changes'])
    </form>
@endsection
