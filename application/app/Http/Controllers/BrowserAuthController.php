<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\VerificationCodeRequest;
use App\Http\Requests\AuthRegistration;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Services\AccountService;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class BrowserAuthController extends Controller
{
    public function register(AuthRegistration $request, AuthService $auth)
    {
        // The API registration rules are shared; confirmation is required by the browser form.
        $request->validate(['password' => ['min:8', 'confirmed'], 'password_confirmation' => ['required', 'string']]);
        $user = $auth->register($request->toDTO());
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $user->getAuthPassword());

        return response()->json(['redirect' => route('verification')], 201);
    }

    public function login(LoginRequest $request)
    {
        $guard = Auth::guard('web');
        if (!$guard->validate($request->validated())) {
            throw ValidationException::withMessages(['email' => 'Неверный email или пароль.']);
        }
        $user = $guard->getLastAttempted();
        abort_if($user->is_blocked, 403, 'Ваш аккаунт заблокирован.');
        $guard->login($user);
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $user->getAuthPassword());

        return response()->json(['redirect' => $request->session()->pull('url.intended', route('account'))]);
    }

    public function verify(VerificationCodeRequest $request, AuthService $auth)
    {
        $auth->verifyEmail($request->validated('code'));

        return response()->json(['success' => true]);
    }

    public function password(UpdatePasswordRequest $request, AccountService $accounts)
    {
        $accounts->updatePassword($request->user(), $request->validated());

        return $this->logout($request);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
