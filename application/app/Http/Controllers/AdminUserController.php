<?php

namespace App\Http\Controllers;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Models\User;

class AdminUserController extends Controller
{
    public function block(User $user)
    {
        $user->is_blocked = true;
        $user->save();

        return response()->json([
            'message' => 'User blocked',
        ]);
    }

    public function unblock(User $user)
    {
        $user->is_blocked = false;
        $user->save();

        return response()->json([
            'message' => 'User unblocked',
        ]);
    }

    public function changeRole(UpdateUserRoleRequest $request ,User $user)
    {
        $user->role = $request->role;
        $user->save();

        return response()->json([
            'message' => 'User role updated',
        ]);
    }

}
