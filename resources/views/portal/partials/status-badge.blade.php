@php
    $statusTone = match ($status) {
        'delivered' => 'success',
        'failed', 'cancelled' => 'danger',
        'on_the_way', 'arrived' => 'live',
        'assigned', 'accepted' => 'info',
        default => 'neutral',
    };
@endphp
<span class="portal-status portal-status--{{ $statusTone }}">
    <span aria-hidden="true"></span>
    {{ str($status)->replace('_', ' ')->title() }}
</span>
