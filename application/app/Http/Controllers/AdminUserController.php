<?php

namespace App\Http\Controllers;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Models\User;
use App\Models\Role;
use App\Services\UserAccessService;

class AdminUserController extends Controller
{
    public function index()
    {
        return User::paginate(15);
    }
    public function block(User $user, UserAccessService $access)
    {
        $access->block($user);

        return response()->json([
            'message' => 'User blocked',
        ]);
    }

    public function unblock(User $user, UserAccessService $access)
    {
        $access->unblock($user);

        return response()->json([
            'message' => 'User unblocked',
        ]);
    }

    public function changeRole(UpdateUserRoleRequest $request ,User $user)
    {
        $role = Role::where('name', $request->role)->firstOrFail();
        $user->role = $request->role;
        $user->rbacRole()->associate($role);
        $user->save();

        return response()->json([
            'message' => 'User role updated',
        ]);
    }

}
