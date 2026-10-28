<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\BrowserAuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PurchasePageController;
use App\Http\Controllers\WaitlistPageController;
use App\Http\Controllers\OrganizerEventPageController;
use App\Http\Controllers\OrganizerTicketTypePageController;
use App\Http\Controllers\OrganizerReportPageController;
use App\Http\Controllers\AdminUserPageController;
use App\Http\Controllers\AdminCategoryPageController;
use App\Http\Controllers\AdminVenuePageController;

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
    Route::prefix('/admin/categories')->middleware('can:categories.view')->group(function () {
        Route::get('/', [AdminCategoryPageController::class, 'index'])->name('admin.categories.index');
        Route::get('/create', [AdminCategoryPageController::class, 'create'])->middleware('can:categories.create')->name('admin.categories.create');
        Route::post('/', [AdminCategoryPageController::class, 'store'])->middleware(['can:categories.create', 'throttle:20,1'])->name('admin.categories.store');
        Route::get('/{category}/edit', [AdminCategoryPageController::class, 'edit'])->whereNumber('category')
            ->middleware('can:categories.update')->name('admin.categories.edit');
        Route::patch('/{category}', [AdminCategoryPageController::class, 'update'])->whereNumber('category')
            ->middleware(['can:categories.update', 'throttle:20,1'])->name('admin.categories.update');
        Route::delete('/{category}', [AdminCategoryPageController::class, 'destroy'])->whereNumber('category')
            ->middleware(['can:categories.delete', 'throttle:20,1'])->name('admin.categories.destroy');
    });
    Route::prefix('/admin/venues')->middleware('can:venues.view')->group(function () {
        Route::get('/', [AdminVenuePageController::class, 'index'])->name('admin.venues.index');
        Route::get('/create', [AdminVenuePageController::class, 'create'])->middleware('can:venues.create')->name('admin.venues.create');
        Route::post('/', [AdminVenuePageController::class, 'store'])->middleware(['can:venues.create', 'throttle:20,1'])->name('admin.venues.store');
        Route::get('/{venue}/edit', [AdminVenuePageController::class, 'edit'])->whereNumber('venue')
            ->middleware('can:venues.update')->name('admin.venues.edit');
        Route::patch('/{venue}', [AdminVenuePageController::class, 'update'])->whereNumber('venue')
            ->middleware(['can:venues.update', 'throttle:20,1'])->name('admin.venues.update');
        Route::delete('/{venue}', [AdminVenuePageController::class, 'destroy'])->whereNumber('venue')
            ->middleware(['can:venues.delete', 'throttle:20,1'])->name('admin.venues.destroy');
    });
    Route::prefix('/admin/users')->middleware('can:users.view')->group(function () {
        Route::get('/', [AdminUserPageController::class, 'index'])->name('admin.users.index');
        Route::get('/{user}', [AdminUserPageController::class, 'show'])->whereNumber('user')->name('admin.users.show');
        Route::patch('/{user}/role', [AdminUserPageController::class, 'role'])->whereNumber('user')
            ->middleware(['can:users.update-role', 'throttle:20,1'])->name('admin.users.role');
        Route::patch('/{user}/block', [AdminUserPageController::class, 'block'])->whereNumber('user')
            ->middleware(['can:users.block', 'throttle:20,1'])->name('admin.users.block');
        Route::patch('/{user}/unblock', [AdminUserPageController::class, 'unblock'])->whereNumber('user')
            ->middleware(['can:users.unblock', 'throttle:20,1'])->name('admin.users.unblock');
    });
    Route::prefix('/organizer/events')->middleware('can:events.create')->group(function () {
        Route::prefix('/{event}/reports')->whereNumber(['event', 'ticket'])->middleware('can:viewReports,event')->scopeBindings()->group(function () {
            Route::get('/sales', [OrganizerReportPageController::class, 'sales'])->name('organizer.reports.sales');
            Route::get('/participants', [OrganizerReportPageController::class, 'participants'])->name('organizer.reports.participants');
            Route::get('/check-in', [OrganizerReportPageController::class, 'checkIn'])->name('organizer.reports.check-in');
            Route::post('/tickets/{ticket}/check-in', [OrganizerReportPageController::class, 'storeCheckIn'])
                ->middleware(['can:checkIn,ticket,event', 'throttle:60,1'])->name('organizer.reports.check-in.store');
        });
        Route::post('/{event}/publish', [OrganizerEventPageController::class, 'publish'])->whereNumber('event')
            ->middleware(['can:publish,event', 'throttle:20,1'])->name('organizer.events.publish');
        Route::post('/{event}/cancel', [OrganizerEventPageController::class, 'cancel'])->whereNumber('event')
            ->middleware(['can:cancel,event', 'throttle:20,1'])->name('organizer.events.cancel');
        Route::prefix('/{event}/ticket-types')->whereNumber(['event', 'ticketType'])->scopeBindings()
            ->middleware('can:update,event')->group(function () {
                Route::get('/', [OrganizerTicketTypePageController::class, 'index'])->name('organizer.tickets.index');
                Route::get('/create', [OrganizerTicketTypePageController::class, 'create'])->middleware('can:create,App\Models\TicketType,event')->name('organizer.tickets.create');
                Route::post('/', [OrganizerTicketTypePageController::class, 'store'])->middleware('throttle:20,1')->name('organizer.tickets.store');
                Route::get('/{ticketType}/edit', [OrganizerTicketTypePageController::class, 'edit'])->middleware('can:update,ticketType,event')->name('organizer.tickets.edit');
                Route::patch('/{ticketType}', [OrganizerTicketTypePageController::class, 'update'])->middleware('throttle:20,1')->name('organizer.tickets.update');
                Route::patch('/{ticketType}/quantity', [OrganizerTicketTypePageController::class, 'quantity'])->middleware('throttle:20,1')->name('organizer.tickets.quantity');
            });
        Route::get('/', [OrganizerEventPageController::class, 'index'])->name('organizer.events.index');
        Route::get('/create', [OrganizerEventPageController::class, 'create'])->name('organizer.events.create');
        Route::post('/', [OrganizerEventPageController::class, 'store'])->middleware('throttle:20,1')->name('organizer.events.store');
        Route::get('/{event}/edit', [OrganizerEventPageController::class, 'edit'])->whereNumber('event')
            ->middleware('can:update,event')->name('organizer.events.edit');
        Route::patch('/{event}', [OrganizerEventPageController::class, 'update'])->whereNumber('event')
            ->middleware(['can:update,event', 'throttle:20,1'])->name('organizer.events.update');
    });
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
