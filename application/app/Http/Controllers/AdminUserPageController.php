<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Admin\IndexAdminUsersRequest;
use App\Http\Requests\Admin\UpdateUserRolePageRequest;
use App\Http\Requests\ConfirmWebActionRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminUserPageController extends Controller
{
    public function index(IndexAdminUsersRequest $request)
    {
        $filters = $request->validated();
        $query = User::query()->select(['id', 'name', 'email', 'role_id', 'is_blocked', 'email_verified_at', 'created_at'])
            ->with('rbacRole:id,name');
        if (!empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn ($query) => $query->whereLike('name', $search)->orWhereLike('email', $search));
        }
        if (!empty($filters['role'])) {
            $query->whereHas('rbacRole', fn ($query) => $query->where('name', $filters['role']));
        }
        if (!empty($filters['state'])) {
            $query->where('is_blocked', $filters['state'] === 'blocked');
        }
        $users = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(12)->withQueryString();

        return response()->view('admin.users.index', compact('users', 'filters'))->header('Cache-Control', 'private, no-store');
    }

    public function show(User $user)
    {
        $roles = Role::query()->whereIn('name', array_column(UserRole::cases(), 'value'))->orderBy('name')->get();

        return response()->view('admin.users.show', ['managedUser' => $user->load('rbacRole'), 'roles' => $roles])
            ->header('Cache-Control', 'private, no-store');
    }

    public function role(UpdateUserRolePageRequest $request, User $user, UserAccessService $access)
    {
        $this->ensureAnotherUser($request, $user);
        $access->changeRole($user, $request->validated('role'));

        return redirect()->route('admin.users.show', $user)->with('admin_success', 'Роль пользователя изменена.');
    }

    public function block(ConfirmWebActionRequest $request, User $user, UserAccessService $access)
    {
        $this->ensureAnotherUser($request, $user);
        $access->block($user);

        return redirect()->route('admin.users.show', $user)->with('admin_success', 'Пользователь заблокирован. Доступ к защищённым действиям закрыт, API-токены отозваны.');
    }

    public function unblock(ConfirmWebActionRequest $request, User $user, UserAccessService $access)
    {
        $access->unblock($user);

        return redirect()->route('admin.users.show', $user)->with('admin_success', 'Пользователь разблокирован. Отозванные API-токены не восстанавливаются.');
    }

    private function ensureAnotherUser(Request $request, User $user): void
    {
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages(['user' => 'Блокировка своего аккаунта и смена собственной роли в этой форме недоступны. Обратитесь к другому администратору.']);
        }
    }
}
