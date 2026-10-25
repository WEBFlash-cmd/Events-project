<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;

class AuthenticateActiveUser extends Authenticate
{
    protected function authenticate($request, array $guards)
    {
        parent::authenticate($request, $guards);

        $user = User::query()->select(['id', 'is_blocked'])->find($request->user()->getAuthIdentifier());
        abort_unless($user !== null, 401);
        abort_if($user->is_blocked, 403, 'Your account is blocked');
    }
}
