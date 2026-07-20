<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\DeliveryDriverController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\DriverDeliveryWorkflowController;
use App\Http\Controllers\Api\DriverLocationController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:auth-login')
    ->name('auth.login');

Route::middleware(['auth:sanctum', 'active.api.user'])->group(function (): void {
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');

    Route::get('drivers/available', [DriverController::class, 'available'])
        ->name('drivers.available');

    Route::get('driver/deliveries', [DriverController::class, 'deliveries'])
        ->name('driver.deliveries');

    Route::get('driver/deliveries/{delivery}', [DriverDeliveryWorkflowController::class, 'show'])
        ->name('driver.deliveries.show');

    Route::post('driver/deliveries/{delivery}/start', [DriverDeliveryWorkflowController::class, 'start'])
        ->name('driver.deliveries.start');

    Route::post('driver/deliveries/{delivery}/deliver', [DriverDeliveryWorkflowController::class, 'deliver'])
        ->name('driver.deliveries.deliver');

    Route::post('driver/deliveries/{delivery}/fail', [DriverDeliveryWorkflowController::class, 'fail'])
        ->name('driver.deliveries.fail');

    Route::post('driver/deliveries/{delivery}/locations', [DriverLocationController::class, 'store'])
        ->middleware('throttle:driver-locations')
        ->name('driver.deliveries.locations.store');

    Route::get('deliveries/{delivery}/tracking-locations', [DriverLocationController::class, 'history'])
        ->name('deliveries.tracking-locations.index');

    Route::post('deliveries/{delivery}/assign-driver', [DeliveryDriverController::class, 'assign'])
        ->name('deliveries.assign-driver');

    Route::post('deliveries/{delivery}/unassign-driver', [DeliveryDriverController::class, 'unassign'])
        ->name('deliveries.unassign-driver');

    Route::post('deliveries/{delivery}/cancel', [DeliveryController::class, 'cancel'])
        ->name('deliveries.cancel');

    Route::apiResource('deliveries', DeliveryController::class);
});
