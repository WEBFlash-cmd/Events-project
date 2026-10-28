<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $permissions = [
            'users.view',
            'users.update-role',
            'users.block',
            'users.unblock',
            'categories.view',
            'categories.create',
            'categories.update',
            'categories.delete',
            'venues.create',
            'venues.view',
            'venues.update',
            'venues.delete',
            'events.create',
        ];

        foreach ($permissions as $permissionName) {
            Gate::define($permissionName, function (User $user) use ($permissionName): bool {
                return $user->hasPermission($permissionName);
            });
        }

        RateLimiter::for("reg", function (Request $request) {
            return Limit::perMinutes(30, 10)->by($request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');

            return Limit::perMinutes(30, 3)
                ->by(is_string($email) && $email !== '' ? Str::lower(trim($email)) : $request->ip())
                ->after(function (Response $response) {
                    return $response->status() === 422;
                });
        });
    }
}
