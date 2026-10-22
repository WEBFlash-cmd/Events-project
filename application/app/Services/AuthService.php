<?php

namespace App\Services;

use App\Data\Auth\LoginData;
use App\Data\Auth\RegistrationData;
use App\Enums\UserRole;
use App\Mail\EmailVerification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Exceptions\BlockedUserException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function register(RegistrationData $data): User
    {
        $role = Role::where('name', UserRole::PARTICIPANT->value)
            ->firstOrFail();
        $user = User::create([
            'email' => $data->email,
            'password' => $data->password,
        ]);
        $user->rbacRole()->associate($role);
        $user->save();
        event(new Registered($user));
        return $user;
    }

    public function login(LoginData $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::query()->where('email', $data->email)->lockForUpdate()->firstOrFail();
            if ($user->is_blocked === true) {
                throw new BlockedUserException('Your account is blocked');
            }
            $user->checkPassword($data->password);
            $token = $user->createToken("login-token");
            return ["token" => $token->plainTextToken];
        });
    }

    public function sendVerificationEmail(User $user): void
    {
        $code = Str::random(6);
        Cache::put("mail-$code", ['user_id' => $user->id, 'email' => $user->email], now()->addHour());
        Mail::to($user)->send(new EmailVerification($code));
    }

    public function verifyEmail(string $code): void
    {
        $verification = Cache::get("mail-$code");
        if (!is_array($verification) || !isset($verification['user_id'], $verification['email'])) {
            throw ValidationException::withMessages(['code' => 'Invalid verification code']);
        }
        DB::transaction(function () use ($verification) {
            $user = User::query()->lockForUpdate()->find($verification['user_id']);
            if ($user === null || $user->email !== $verification['email']) {
                throw ValidationException::withMessages(['code' => 'Invalid verification code']);
            }
            if ($user->email_verified_at === null) {
                $user->email_verified_at = now();
                $user->save();
            }
        });
        Cache::forget("mail-$code");
    }
}
