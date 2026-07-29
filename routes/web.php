<?php

use App\Http\Controllers\CustomerTrackingController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate'])
    ->middleware(['customer.tracking.headers', 'throttle:broadcasting-auth'])
    ->name('broadcasting.auth');

Route::get('/track/{publicTrackingToken}', [CustomerTrackingController::class, 'enter'])
    ->middleware(['customer.tracking.headers', 'throttle:customer-tracking-entry'])
    ->name('customer.tracking.enter');

Route::get('/tracking', [CustomerTrackingController::class, 'page'])
    ->middleware(['customer.tracking.headers'])
    ->name('customer.tracking.page');

Route::get('/tracking/session', [CustomerTrackingController::class, 'show'])
    ->middleware([
        'customer.tracking.headers',
        'throttle:customer-tracking-snapshot',
        'customer.tracking',
    ])
    ->name('customer.tracking.session.show');

Route::delete('/tracking/session', [CustomerTrackingController::class, 'destroy'])
    ->middleware([
        'customer.tracking.headers',
        'throttle:customer-tracking-session-delete',
        'customer.tracking',
    ])
    ->name('customer.tracking.session.destroy');

require __DIR__.'/channels.php';
