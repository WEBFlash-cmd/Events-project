<?php

namespace App\Http\Controllers;

use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->only(['id', 'name', 'email', 'email_verified_at'])]);
    }

    public function update(UpdateProfileRequest $request, AccountService $accounts): JsonResponse
    {
        $user = $accounts->updateProfile($request->user(), $request->validated());
        return response()->json(['data' => $user->only(['id', 'name', 'email', 'email_verified_at'])]);
    }

    public function password(UpdatePasswordRequest $request, AccountService $accounts): Response
    {
        $accounts->updatePassword($request->user(), $request->validated());
        return response()->noContent();
    }

    public function logout(Request $request, AccountService $accounts): Response
    {
        $accounts->logout($request->user());
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        return response()->noContent();
    }
}
