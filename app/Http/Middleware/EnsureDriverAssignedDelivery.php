<?php

namespace App\Http\Middleware;

use App\Models\Delivery;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDriverAssignedDelivery
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! $user->isDriver()) {
            abort(403, 'Only drivers can access this delivery endpoint.');
        }

        $delivery = $this->resolveDelivery($request);

        if (! $delivery) {
            abort(404, 'Delivery was not found.');
        }

        if ((string) $delivery->assigned_driver_id !== (string) $user->getKey()) {
            abort(403, 'This delivery is not assigned to this driver.');
        }

        // TODO: Start-delivery controllers must create tracking only after the driver explicitly starts the delivery.
        // TODO: Delivered, failed, and cancelled controllers must stop the active tracking session immediately.
        return $next($request);
    }

    private function resolveDelivery(Request $request): ?Delivery
    {
        $routeValue = $request->route('delivery')
            ?? $request->route('deliveryId')
            ?? $request->route('delivery_id');

        if ($routeValue instanceof Delivery) {
            return $routeValue;
        }

        if ((is_int($routeValue) || is_string($routeValue)) && $routeValue !== '') {
            return Delivery::query()->find($routeValue);
        }

        return null;
    }
}
