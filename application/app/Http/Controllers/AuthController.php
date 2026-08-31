<?php

namespace App\Http\Controllers;

use App\Data\Auth\LoginData;
use App\Data\Auth\RegistrationData;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\VerificationCodeRequest;
use App\Http\Requests\AuthRegistration;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Exceptions\BlockedUserException;


class AuthController extends Controller
{
    public function register(AuthRegistration $request, AuthService $authService)
    {
        $authService->register($request->toDTO());
        return response()->json(["success" => true], 201);
    }

    public function login(LoginRequest $request, AuthService $authService)
    {
        try {
            return $authService->login($request->toDTO());
        } catch (BlockedUserException $e) {
            abort(403, $e->getMessage());
        } catch (\Throwable $th) {
            throw ValidationException::withMessages([
                "email" => "invalid credentials",
            ]);
        }
    }

    public function verifyEmail(VerificationCodeRequest $request, AuthService $authService)
    {
        $authService->verifyEmail($request->code);
        return ["success" => true];
    }
}
