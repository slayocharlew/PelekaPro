<?php

use App\Http\Controllers\CustomerTrackingController;
use App\Http\Controllers\Portal\DeliveryController as PortalDeliveryController;
use App\Http\Controllers\PortalAuthController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest:web')->group(function (): void {
    Route::get('/login', [PortalAuthController::class, 'create'])->name('login');
    Route::post('/login', [PortalAuthController::class, 'store'])
        ->middleware('throttle:auth-login')
        ->name('login.store');
});

Route::middleware(['auth:web', 'active.web.user', 'role:super_admin,business_owner,business_admin'])
    ->prefix('portal')
    ->name('portal.')
    ->group(function (): void {
        Route::redirect('/', '/portal/deliveries')->name('home');
        Route::get('/deliveries', [PortalDeliveryController::class, 'index'])->name('deliveries.index');
        Route::get('/deliveries/create', [PortalDeliveryController::class, 'create'])->name('deliveries.create');
        Route::post('/deliveries', [PortalDeliveryController::class, 'store'])->name('deliveries.store');
        Route::get('/deliveries/{delivery}', [PortalDeliveryController::class, 'show'])->name('deliveries.show');
        Route::get('/deliveries/{delivery}/edit', [PortalDeliveryController::class, 'edit'])->name('deliveries.edit');
        Route::put('/deliveries/{delivery}', [PortalDeliveryController::class, 'update'])->name('deliveries.update');
        Route::post('/deliveries/{delivery}/assign-driver', [PortalDeliveryController::class, 'assign'])->name('deliveries.assign');
        Route::delete('/deliveries/{delivery}/assigned-driver', [PortalDeliveryController::class, 'unassign'])->name('deliveries.unassign');
        Route::post('/deliveries/{delivery}/cancel', [PortalDeliveryController::class, 'cancel'])->name('deliveries.cancel');
    });

Route::post('/logout', [PortalAuthController::class, 'destroy'])
    ->middleware('auth:web')
    ->name('logout');

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
