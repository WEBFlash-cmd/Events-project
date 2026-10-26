<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\VenueController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\TicketTypeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\EventReportController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\PurchaseController as AdminPurchaseController;
use App\Http\Controllers\Admin\EventController as AdminEventController;
use App\Http\Controllers\WaitlistController;

Route::patterns(array_fill_keys(['event', 'order', 'ticket', 'ticketType', 'user', 'category'], '[0-9]+'));

Route::get('/waitlist', [WaitlistController::class, 'index'])->middleware('auth:sanctum');

Route::prefix('/events/{event}/ticket-types/{ticketType}/waitlist')->middleware('auth:sanctum')
    ->scopeBindings()->group(function () {
        Route::post('/', [WaitlistController::class, 'store']);
        Route::get('/', [WaitlistController::class, 'show']);
        Route::delete('/', [WaitlistController::class, 'destroy']);
        Route::post('/checkout', [WaitlistController::class, 'checkout']);
    });

Route::get('/admin/events', [AdminEventController::class, 'index'])
    ->middleware(['auth:sanctum', 'can:viewAny,App\Models\Event']);

Route::get('/admin/events/{event}', [AdminEventController::class, 'show'])
    ->whereNumber('event')
    ->middleware(['auth:sanctum', 'can:view,event']);

Route::delete('/admin/events/{event}', [AdminEventController::class, 'destroy'])
    ->whereNumber('event')
    ->middleware(['auth:sanctum', 'can:delete,event']);

Route::get('/admin/orders', [AdminPurchaseController::class, 'orders'])
    ->name('admin.orders.index')
    ->middleware(['auth:sanctum', 'can:viewAny,App\Models\Order']);

Route::get('/admin/tickets', [AdminPurchaseController::class, 'tickets'])
    ->name('admin.tickets.index')
    ->middleware(['auth:sanctum', 'can:viewAny,App\Models\Ticket']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AccountController::class, 'show']);
    Route::patch('/me', [AccountController::class, 'update'])->middleware('throttle:10,1');
    Route::patch('/me/password', [AccountController::class, 'password'])->middleware('throttle:10,1');
    Route::post('/auth/logout', [AccountController::class, 'logout']);
});

Route::get('/events/{event}/participants', [EventReportController::class, 'participants'])
    ->whereNumber('event')
    ->middleware(['auth:sanctum', 'can:viewReports,event']);

Route::get('/events/{event}/sales', [EventReportController::class, 'sales'])
    ->whereNumber('event')
    ->middleware(['auth:sanctum', 'can:viewReports,event']);

Route::post('/payments/webhook', PaymentWebhookController::class);

Route::get('/orders', [OrderController::class, 'index'])->middleware('auth:sanctum');

Route::get('/orders/{order}', [OrderController::class, 'show'])
    ->middleware(['auth:sanctum', 'can:view,order']);

Route::get('/tickets', [TicketController::class, 'index'])->middleware('auth:sanctum');

Route::get('/tickets/{ticket}', [TicketController::class, 'show'])
    ->whereNumber('ticket')
    ->middleware(['auth:sanctum', 'can:view,ticket']);

Route::post('/tickets/{ticket}/cancel', [TicketController::class, 'cancel'])
    ->whereNumber('ticket')
    ->middleware(['auth:sanctum', 'can:cancel,ticket']);

Route::post('/events/{event}/tickets/{ticket}/check-in', [TicketController::class, 'checkIn'])
    ->whereNumber(['event', 'ticket'])
    ->scopeBindings()
    ->middleware(['auth:sanctum', 'can:checkIn,ticket,event']);

Route::patch('/admin/users/{user}/block', [AdminUserController::class, 'block'])
    ->middleware(['auth:sanctum', 'can:users.block']);

Route::patch('/admin/users/{user}/unblock', [AdminUserController::class, 'unblock'])
    ->middleware(['auth:sanctum', 'can:users.unblock']);

Route::patch('/admin/users/{user}/role', [AdminUserController::class, 'changeRole'])
    ->middleware(['auth:sanctum', 'can:users.update-role']);

Route::patch('/admin/categories/{category}', [CategoryController::class, 'update'])
    ->middleware(['auth:sanctum', 'can:categories.update']);

Route::get('/admin/users', [AdminUserController::class, 'index'])
    ->middleware(['auth:sanctum', 'can:users.view']);

Route::get("/health", HealthController::class);

Route::post('/admin/categories', [CategoryController::class, 'store'])
    ->middleware(['auth:sanctum', 'can:categories.create']);

Route::get('/admin/categories', [CategoryController::class, 'index'])
    ->middleware(['auth:sanctum', 'can:categories.view']);

Route::get('/admin/categories/{category}', [CategoryController::class, 'show'])
    ->middleware(['auth:sanctum', 'can:categories.view']);

Route::post('/admin/venues', [VenueController::class, 'store'])
    ->middleware(['auth:sanctum', 'can:venues.create']);

Route::get('/venues', [VenueController::class, 'index'])
    ->middleware(['auth:sanctum', 'can:venues.view']);

Route::post('/events', [EventController::class, 'store'])
    ->middleware(['auth:sanctum', 'can:events.create']);

Route::patch('/events/{event}', [EventController::class, 'update'])
    ->middleware(['auth:sanctum', 'can:update,event']);

Route::post('/events/{event}/publish', [EventController::class, 'publish'])
    ->middleware(['auth:sanctum', 'can:publish,event']);

Route::post('/events/{event}/cancel', [EventController::class, 'cancel'])
    ->whereNumber('event')
    ->middleware(['auth:sanctum', 'can:cancel,event']);

Route::get('/events', [EventController::class, 'index']);

Route::get('/events/{event}', [EventController::class, 'show']);

Route::post('/events/{event}/orders', [OrderController::class, 'store'])
    ->middleware('auth:sanctum');

Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])
    ->middleware(['auth:sanctum', 'can:cancel,order']);

Route::get('/events/{event}/ticket-types', [TicketTypeController::class, 'index']);

Route::post('/events/{event}/ticket-types', [TicketTypeController::class, 'store'])
    ->middleware('auth:sanctum');

Route::patch('/events/{event}/ticket-types/{ticketType}', [TicketTypeController::class, 'update'])
    ->middleware('auth:sanctum')
    ->scopeBindings();

Route::prefix("/auth")
    ->as("auth.")
    ->group(function () {
        Route::post("/register", [AuthController::class, "register"])
            ->name("register")
            ->middleware(["throttle:reg"]);
        Route::post("/login", [AuthController::class, "login"])
            ->name("login")
            ->middleware(["throttle:login"]);
        Route::post("verify", [AuthController::class, "verifyEmail"])
            ->name("verify");
    });

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::delete('/admin/categories/{category}', [CategoryController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'can:categories.delete']);
