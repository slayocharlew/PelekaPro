@php
    $statusTone = match ($status) {
        'delivered', 'converted', 'available', 'active' => 'success',
        'failed', 'cancelled', 'revoked', 'expired', 'suspended', 'inactive' => 'danger',
        'on_the_way', 'arrived', 'on_delivery' => 'live',
        'assigned', 'accepted', 'submitted' => 'info',
        default => 'neutral',
    };
@endphp
<span class="portal-status portal-status--{{ $statusTone }}">
    <span aria-hidden="true"></span>
    {{ str($status)->replace('_', ' ')->title() }}
</span>
