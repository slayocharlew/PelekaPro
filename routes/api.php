<?php

use App\Http\Controllers\Api\DeliveryController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::post('deliveries/{delivery}/cancel', [DeliveryController::class, 'cancel'])
        ->name('deliveries.cancel');

    Route::apiResource('deliveries', DeliveryController::class);
});
