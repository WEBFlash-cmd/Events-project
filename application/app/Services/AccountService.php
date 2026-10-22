<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AccountService
{
    public function __construct(private AuthService $auth)
    {
    }

    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $user->tokens()->whereKey($token->id)->delete();
        }
    }

    public function updateProfile(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $emailChanged = isset($data['email']) && $data['email'] !== $user->email;
            if ($emailChanged) {
                $this->checkCurrentPassword($user, $data['current_password'] ?? '');
                $user->email = $data['email'];
                $user->email_verified_at = null;
            }
            if (array_key_exists('name', $data)) {
                $user->name = $data['name'];
            }
            $user->save();
            if ($emailChanged) {
                DB::afterCommit(fn () => $this->auth->sendVerificationEmail($user));
            }
            return $user;
        });
    }

    public function updatePassword(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->checkCurrentPassword($user, $data['current_password']);
            $user->password = $data['password'];
            $user->remember_token = Str::random(60);
            $user->save();
            $user->tokens()->delete();
        });
    }

    private function checkCurrentPassword(User $user, string $password): void
    {
        if (!Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Текущий пароль неверен.']);
        }
    }
}
