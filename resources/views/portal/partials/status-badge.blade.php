@php
    $statusTone = match ($status) {
        'delivered', 'converted' => 'success',
        'failed', 'cancelled', 'revoked', 'expired' => 'danger',
        'on_the_way', 'arrived' => 'live',
        'assigned', 'accepted', 'submitted' => 'info',
        default => 'neutral',
    };
@endphp
<span class="portal-status portal-status--{{ $statusTone }}">
    <span aria-hidden="true"></span>
    {{ str($status)->replace('_', ' ')->title() }}
</span>
