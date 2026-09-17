<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\Admin\CategoryController;

Route::patch('/admin/users/{user}/block', [AdminUserController::class, 'block'])
    ->middleware(['auth:sanctum', 'can:manage-users']);

Route::patch('/admin/users/{user}/unblock', [AdminUserController::class, 'unblock'])
    ->middleware(['auth:sanctum', 'can:manage-users']);

Route::patch('/admin/users/{user}/role', [AdminUserController::class, 'changeRole'])
    ->middleware(['auth:sanctum', 'can:manage-users']);

Route::patch('/admin/categories/{category}', [CategoryController::class, 'update'])
    ->middleware(['auth:sanctum', 'can:manage-categories']);

Route::get('/admin/users', [AdminUserController::class, 'index'])
    ->middleware(['auth:sanctum', 'can:manage-users']);

Route::get("/health", HealthController::class);

Route::post('/admin/categories', [CategoryController::class, 'store'])
    ->middleware(['auth:sanctum', 'can:manage-categories']);

Route::get('/admin/categories', [CategoryController::class, 'index'])
    ->middleware(['auth:sanctum', 'can:manage-categories']);

Route::get('/admin/categories/{category}', [CategoryController::class, 'show'])
    ->middleware(['auth:sanctum', 'can:manage-categories']);

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
    ->middleware(['auth:sanctum', 'can:manage-categories']);
