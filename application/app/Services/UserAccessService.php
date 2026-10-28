<?php

namespace App\Services;

use App\Models\User;
use App\Models\Role;
use App\Enums\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserAccessService
{
    public function changeRole(User $user, string $roleName): void
    {
        DB::transaction(function () use ($user, $roleName) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $role = Role::query()->where('name', $roleName)->firstOrFail();
            $user->role = UserRole::from($roleName);
            $user->rbacRole()->associate($role);
            $user->save();
        });
    }

    public function block(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $user->is_blocked = true;
            $user->remember_token = Str::random(60);
            $user->save();
            $user->tokens()->delete();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)->delete();
            }
        });
    }

    public function unblock(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $user->is_blocked = false;
            $user->save();
        });
    }
}
