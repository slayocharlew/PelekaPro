<?php

use App\Http\Controllers\CustomerDeliveryRequestController;
use App\Http\Controllers\CustomerTrackingController;
use App\Http\Controllers\Portal\BusinessController as PortalBusinessController;
use App\Http\Controllers\Portal\CustomerDeliveryRequestController as PortalCustomerDeliveryRequestController;
use App\Http\Controllers\Portal\DeliveryController as PortalDeliveryController;
use App\Http\Controllers\PortalAuthController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

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

        Route::get('/delivery-requests', [PortalCustomerDeliveryRequestController::class, 'index'])->name('delivery-requests.index');
        Route::get('/delivery-requests/create', [PortalCustomerDeliveryRequestController::class, 'create'])->name('delivery-requests.create');
        Route::post('/delivery-requests', [PortalCustomerDeliveryRequestController::class, 'store'])->name('delivery-requests.store');
        Route::get('/delivery-requests/{customerDeliveryRequest}', [PortalCustomerDeliveryRequestController::class, 'show'])->name('delivery-requests.show');
        Route::post('/delivery-requests/{customerDeliveryRequest}/regenerate-link', [PortalCustomerDeliveryRequestController::class, 'regenerate'])->name('delivery-requests.regenerate');
        Route::post('/delivery-requests/{customerDeliveryRequest}/revoke', [PortalCustomerDeliveryRequestController::class, 'revoke'])->name('delivery-requests.revoke');
        Route::post('/delivery-requests/{customerDeliveryRequest}/create-delivery', [PortalCustomerDeliveryRequestController::class, 'convert'])->name('delivery-requests.convert');

        Route::middleware('role:super_admin')->group(function (): void {
            Route::get('/businesses', [PortalBusinessController::class, 'index'])->name('businesses.index');
            Route::get('/businesses/create', [PortalBusinessController::class, 'create'])->name('businesses.create');
            Route::post('/businesses', [PortalBusinessController::class, 'store'])->name('businesses.store');
            Route::get('/businesses/{business}', [PortalBusinessController::class, 'show'])->name('businesses.show');
        });
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

Route::get('/request-delivery/{token}', [CustomerDeliveryRequestController::class, 'enter'])
    ->middleware(['customer.delivery-request.headers', 'throttle:customer-delivery-request-entry'])
    ->name('customer.delivery-request.enter');

Route::get('/delivery-request', [CustomerDeliveryRequestController::class, 'page'])
    ->middleware(['customer.delivery-request.headers'])
    ->name('customer.delivery-request.page');

Route::post('/delivery-request/session', [CustomerDeliveryRequestController::class, 'store'])
    ->middleware([
        'customer.delivery-request.headers',
        'throttle:customer-delivery-request-submit',
        'customer.delivery-request',
    ])
    ->name('customer.delivery-request.session.store');

Route::delete('/delivery-request/session', [CustomerDeliveryRequestController::class, 'destroy'])
    ->middleware([
        'customer.delivery-request.headers',
        'throttle:customer-delivery-request-session-delete',
        'customer.delivery-request',
    ])
    ->name('customer.delivery-request.session.destroy');

Route::get('/delivery-request/submitted', [CustomerDeliveryRequestController::class, 'submitted'])
    ->middleware(['customer.delivery-request.headers'])
    ->name('customer.delivery-request.submitted');

require __DIR__.'/channels.php';
