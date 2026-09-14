<?php

namespace App\Broadcasting;

use App\Auth\CustomerTrackingPrincipal;
use App\Services\CustomerTrackingChannelAlias;
use App\Services\CustomerTrackingSessionService;

final class CustomerDeliveryTrackingChannel
{
    public function __construct(
        private readonly CustomerTrackingSessionService $sessions,
        private readonly CustomerTrackingChannelAlias $aliases,
    ) {}

    public function join(CustomerTrackingPrincipal $principal, string $channelAlias): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $channelAlias) !== 1
            || ! hash_equals($principal->channelAlias, $channelAlias)
        ) {
            return false;
        }

        $delivery = $this->sessions->deliveryForPrincipal($principal);

        return $delivery !== null
            && hash_equals(
                $this->aliases->forToken((string) $delivery->public_tracking_token),
                $channelAlias
            );
    }
}
