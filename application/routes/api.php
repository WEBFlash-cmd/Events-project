<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminUserController;

Route::patch('/admin/users/{user}/block', [AdminUserController::class, 'block'])
    ->middleware(['auth:sanctum', 'can:manage-users']);

Route::patch('/admin/users/{user}/unblock', [AdminUserController::class, 'unblock'])
    ->middleware(['auth:sanctum', 'can:manage-users']);

Route::patch('/admin/users/{user}/role', [AdminUserController::class, 'changeRole'])
    ->middleware(['auth:sanctum', 'can:manage-users']);

Route::get("/health", HealthController::class);

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
