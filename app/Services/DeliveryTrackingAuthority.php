<?php

namespace App\Services;

use App\Exceptions\DeliveryWorkflowException;
use App\Models\Delivery;
use App\Models\DeliveryTrackingSession;
use App\Models\User;

final class DeliveryTrackingAuthority
{
    private const ACTIVE_STATUSES = ['on_the_way', 'arrived'];

    /**
     * @return array{delivery: Delivery, session: DeliveryTrackingSession}
     */
    public function activeContext(Delivery $delivery, User $driver, bool $lockForUpdate = false): array
    {
        $query = Delivery::query()->whereKey($delivery->getKey());

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $authoritativeDelivery = $query->firstOrFail();
        $driver->loadMissing('driverProfile');

        if (! $driver->isDriver()
            || $driver->status !== 'active'
            || ! $driver->driverProfile
            || ! in_array($driver->driverProfile->current_status, ['available', 'assigned', 'on_delivery'], true)
            || (string) $authoritativeDelivery->assigned_driver_id !== (string) $driver->getKey()
            || (string) $authoritativeDelivery->business_id !== (string) $driver->business_id
        ) {
            throw new DeliveryWorkflowException('You are not allowed to record locations for this delivery.', 403);
        }

        if ($authoritativeDelivery->started_at === null
            || ! in_array($authoritativeDelivery->status, self::ACTIVE_STATUSES, true)
        ) {
            throw new DeliveryWorkflowException('Location tracking is not active for this delivery');
        }

        $sessions = DeliveryTrackingSession::query()
            ->where('delivery_id', $authoritativeDelivery->getKey())
            ->where('status', 'active')
            ->whereNull('stopped_at');

        if ($lockForUpdate) {
            $sessions->lockForUpdate();
        }

        $activeSessions = $sessions->get();

        if ($activeSessions->count() !== 1) {
            throw new DeliveryWorkflowException('Location tracking is not active for this delivery');
        }

        $session = $activeSessions->first();

        if ((string) $session->driver_id !== (string) $driver->getKey()) {
            throw new DeliveryWorkflowException('You are not allowed to record locations for this delivery.', 403);
        }

        return ['delivery' => $authoritativeDelivery, 'session' => $session];
    }
}
