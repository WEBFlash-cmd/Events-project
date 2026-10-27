<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\BrowserAuthController;

Route::get('/', CatalogController::class)->name('catalog');

Route::middleware('guest:web')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::view('/register', 'auth.register')->name('register');
    Route::post('/login', [BrowserAuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register', [BrowserAuthController::class, 'register'])->middleware('throttle:reg');
});

Route::view('/verify-email', 'auth.verify')->name('verification');
Route::post('/verify-email', [BrowserAuthController::class, 'verify'])->middleware('throttle:10,1');

Route::middleware(['auth:web', 'auth.session'])->group(function () {
    Route::view('/account', 'account')->name('account');
    Route::get('/account/profile', [AccountController::class, 'show']);
    Route::patch('/account/profile', [AccountController::class, 'update'])->middleware('throttle:10,1');
    Route::patch('/account/password', [BrowserAuthController::class, 'password'])->middleware('throttle:10,1');
});
// Logout remains available even if an account has been blocked.
Route::post('/logout', [BrowserAuthController::class, 'logout']);
