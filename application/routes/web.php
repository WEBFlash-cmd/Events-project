<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\BrowserAuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PurchasePageController;
use App\Http\Controllers\WaitlistPageController;

Route::get('/', CatalogController::class)->name('catalog');
Route::get('/events/{event}', [CheckoutController::class, 'event'])->whereNumber('event')->name('events.show');

Route::middleware('guest:web')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::view('/register', 'auth.register')->name('register');
    Route::post('/login', [BrowserAuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register', [BrowserAuthController::class, 'register'])->middleware('throttle:reg');
});

Route::view('/verify-email', 'auth.verify')->name('verification');
Route::post('/verify-email', [BrowserAuthController::class, 'verify'])->middleware('throttle:10,1');

Route::middleware(['auth:web', 'auth.session'])->group(function () {
    Route::get('/waitlist', [WaitlistPageController::class, 'index'])->name('waitlist.index');
    Route::prefix('/events/{event}/ticket-types/{ticketType}/waitlist')->whereNumber(['event', 'ticketType'])
        ->middleware('throttle:20,1')->group(function () {
            Route::post('/join', [WaitlistPageController::class, 'join'])->name('waitlist.join');
            Route::post('/leave', [WaitlistPageController::class, 'leave'])->name('waitlist.leave');
            Route::post('/accept', [WaitlistPageController::class, 'accept'])->name('waitlist.accept');
        });
    Route::get('/orders', [PurchasePageController::class, 'orders'])->name('purchases.orders');
    Route::get('/tickets', [PurchasePageController::class, 'tickets'])->name('purchases.tickets');
    Route::get('/tickets/{ticket}', [PurchasePageController::class, 'ticket'])->whereNumber('ticket')->name('purchases.ticket');
    Route::post('/tickets/{ticket}/cancel', [PurchasePageController::class, 'cancel'])->whereNumber('ticket')->middleware('throttle:10,1')->name('purchases.ticket.cancel');
    Route::post('/events/{event}/checkout', [CheckoutController::class, 'store'])->whereNumber('event')->middleware('throttle:20,1');
    Route::get('/orders/{order}', [CheckoutController::class, 'order'])->whereNumber('order')->name('checkout.order');
    Route::get('/orders/{order}/status', [OrderController::class, 'show'])->whereNumber('order')->middleware('can:view,order');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->whereNumber('order')->middleware('can:cancel,order');
    Route::get('/payment/result', [CheckoutController::class, 'result'])->name('checkout.result');
    Route::view('/account', 'account')->name('account');
    Route::get('/account/profile', [AccountController::class, 'show']);
    Route::patch('/account/profile', [AccountController::class, 'update'])->middleware('throttle:10,1');
    Route::patch('/account/password', [BrowserAuthController::class, 'password'])->middleware('throttle:10,1');
});
// Logout remains available even if an account has been blocked.
Route::post('/logout', [BrowserAuthController::class, 'logout']);
