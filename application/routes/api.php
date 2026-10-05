<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\VenueController;

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
