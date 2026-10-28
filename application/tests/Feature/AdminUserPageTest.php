<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAccessService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    public function testGuestsParticipantsAndOrganizersCannotReadOrChangeUsers(): void
    {
        $target = User::factory()->create();
        $base = '/admin/users/'.$target->id;
        $this->get('/admin/users')->assertRedirect('/login');
        $this->get($base)->assertRedirect('/login');
        foreach ([UserRole::PARTICIPANT, UserRole::ORGANIZER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/admin/users')->assertForbidden();
            $this->get($base)->assertForbidden();
            $this->patch($base.'/role', ['role' => 'admin', 'confirm_role' => 1])->assertForbidden();
            $this->patch($base.'/block', ['confirm_block' => 1])->assertForbidden();
            $this->patch($base.'/unblock', ['confirm_unblock' => 1])->assertForbidden();
            $this->get('/account')->assertDontSee('href="'.route('admin.users.index').'"', false);
        }
    }

    public function testAdminSeesSafeAccountInformationAndNavigation(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['name' => 'Иван', 'email' => 'ivan@example.test']);
        $token = $target->createToken('private-token')->plainTextToken;
        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee('Иван')->assertSee($target->email)
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee($target->password)->assertDontSee($token);
        $this->get('/admin/users/'.$target->id)->assertOk()->assertSee('Смена роли')->assertSee('Заблокировать пользователя')
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee($target->password)->assertDontSee($token);
        $this->get('/account')->assertSee(route('admin.users.index'));
    }

    public function testSearchAndRoleAndStateFiltersWorkTogether(): void
    {
        $admin = $this->admin();
        $match = User::factory()->create(['name' => 'Target Person', 'email' => 'find-me@example.test', 'role' => UserRole::ORGANIZER, 'is_blocked' => true]);
        User::factory()->create(['name' => 'Target Other', 'role' => UserRole::PARTICIPANT, 'is_blocked' => true]);
        $this->actingAs($admin)->get('/admin/users?search=TARGET&role=organizer&state=blocked')->assertOk()
            ->assertViewHas('users', fn ($users) => $users->total() === 1 && $users->first()->id === $match->id);
        $this->get('/admin/users?search=find-me')->assertOk()->assertSee($match->email);
        $this->get('/admin/users?state=active')->assertOk()->assertDontSee($match->email);
        $this->get('/admin/users?search=nonexistent-unique-search')->assertOk()->assertSee('Пользователи не найдены');
    }

    public function testUsersArePaginatedNewestFirstAndFiltersSurvivePaging(): void
    {
        $admin = $this->admin();
        $targets = User::factory()->count(13)->create(['name' => 'Pagination Match']);
        $this->actingAs($admin)->get('/admin/users?search=Pagination')->assertOk()->assertSee('Далее')
            ->assertViewHas('users', fn ($users) => $users->count() === 12 && $users->first()->id === $targets->last()->id);
        $this->get('/admin/users?search=Pagination&page=2')->assertOk()->assertSee('Назад')
            ->assertViewHas('users', fn ($users) => $users->count() === 1);
    }

    public function testRoleChangeRequiresConfirmationAndSynchronizesRbacAndLegacyRole(): void
    {
        $target = User::factory()->create();
        $base = '/admin/users/'.$target->id.'/role';
        $this->actingAs($this->admin())->patch($base, ['role' => 'organizer'])->assertSessionHasErrors('confirm_role');
        $this->assertSame(UserRole::PARTICIPANT, $target->fresh()->role);
        $this->patch($base, ['role' => 'organizer', 'confirm_role' => 1])->assertRedirect(route('admin.users.show', $target));
        $fresh = $target->fresh();
        $this->assertSame(UserRole::ORGANIZER, $fresh->role);
        $this->assertSame('organizer', $fresh->rbacRole->name);
        $this->assertTrue($fresh->hasPermission('events.create'));
        $this->patch($base, ['role' => 'participant', 'confirm_role' => 1])->assertRedirect();
        $this->assertFalse($target->fresh()->hasPermission('events.create'));
    }

    public function testInvalidRoleAndForgedOtherFieldsCannotChangeAccount(): void
    {
        $target = User::factory()->create(['email' => 'unchanged@example.test']);
        $base = '/admin/users/'.$target->id.'/role';
        $this->actingAs($this->admin())->patch($base, ['role' => 'superadmin', 'confirm_role' => 1])->assertSessionHasErrors('role');
        $this->patch($base, ['role' => 'organizer', 'confirm_role' => 1, 'email' => 'forged@example.test', 'is_blocked' => true,
            'role_id' => Role::where('name', 'admin')->value('id')])->assertRedirect();
        $this->assertSame('unchanged@example.test', $target->fresh()->email);
        $this->assertFalse($target->fresh()->is_blocked);
        $this->assertSame('organizer', $target->fresh()->rbacRole->name);
    }

    public function testBlockRequiresConfirmationRevokesTokensAndPreservesOtherAccounts(): void
    {
        $target = User::factory()->create();
        $target->createToken('phone');
        $other = User::factory()->create();
        $other->createToken('other');
        $base = '/admin/users/'.$target->id.'/block';
        $this->actingAs($this->admin())->patch($base)->assertSessionHasErrors('confirm_block');
        $this->assertFalse($target->fresh()->is_blocked);
        $this->patch($base, ['confirm_block' => 1])->assertRedirect(route('admin.users.show', $target));
        $this->assertTrue($target->fresh()->is_blocked);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
        $this->patch($base, ['confirm_block' => 1])->assertRedirect();
        $this->get('/admin/users/'.$target->id)->assertOk()->assertSee('Разблокировать пользователя');
    }

    public function testUnblockRequiresConfirmationAndDoesNotRestoreTokens(): void
    {
        $target = User::factory()->create();
        $target->createToken('old');
        app(UserAccessService::class)->block($target);
        $base = '/admin/users/'.$target->id.'/unblock';
        $this->actingAs($this->admin())->patch($base)->assertSessionHasErrors('confirm_unblock');
        $this->assertTrue($target->fresh()->is_blocked);
        $this->patch($base, ['confirm_unblock' => 1])->assertRedirect();
        $this->assertFalse($target->fresh()->is_blocked);
        $this->assertSame(0, $target->tokens()->count());
    }

    public function testSelfBlockAndSelfRoleChangeAreRejected(): void
    {
        $admin = $this->admin();
        $base = '/admin/users/'.$admin->id;
        $this->actingAs($admin)->get($base)->assertOk()->assertSee('Это ваш аккаунт.')
            ->assertDontSee('>Изменить роль<', false)->assertDontSee('>Заблокировать пользователя<', false);
        $this->patch($base.'/block', ['confirm_block' => 1])->assertSessionHasErrors('user');
        $this->patch($base.'/role', ['role' => 'participant', 'confirm_role' => 1])->assertSessionHasErrors('user');
        $this->assertFalse($admin->fresh()->is_blocked);
        $this->assertSame(UserRole::ADMIN, $admin->fresh()->role);
    }

    public function testReadPermissionDoesNotGrantMutationPermissions(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $permissions = Permission::whereIn('name', ['users.block', 'users.unblock', 'users.update-role'])->pluck('id');
        $admin->rbacRole->permissions()->detach($permissions);
        $base = '/admin/users/'.$target->id;
        $this->actingAs($admin)->get($base)->assertOk()->assertDontSee('>Изменить роль<', false);
        $this->patch($base.'/role', ['role' => 'admin', 'confirm_role' => 1])->assertForbidden();
        $this->patch($base.'/block', ['confirm_block' => 1])->assertForbidden();
        $this->patch($base.'/unblock', ['confirm_unblock' => 1])->assertForbidden();
        $admin->rbacRole->permissions()->detach(Permission::where('name', 'users.view')->value('id'));
        $this->get('/admin/users')->assertForbidden();
    }

    public function testBlockedAdminCannotReadOrChangeUsers(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $admin->forceFill(['is_blocked' => true])->save();
        $this->actingAs($admin)->get('/admin/users')->assertForbidden();
        $this->patch('/admin/users/'.$target->id.'/role', ['role' => 'admin', 'confirm_role' => 1])->assertForbidden();
    }

    public function testTextIsEscapedAndInvalidFiltersReturnToSafePage(): void
    {
        $target = User::factory()->create(['name' => '<script>bad()</script>']);
        $this->actingAs($this->admin());
        foreach (['/admin/users', '/admin/users/'.$target->id] as $url) {
            $this->get($url)->assertOk()->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)->assertDontSee('<script>bad()</script>', false);
        }
        $this->getJson('/admin/users?search[]=bad')->assertUnprocessable();
        $this->getJson('/admin/users?role=superadmin')->assertUnprocessable();
        $this->getJson('/admin/users?state=invalid')->assertUnprocessable();
        $this->followingRedirects()->get('/admin/users?search[]=bad')->assertOk()->assertSee('Введите имя или email текстом.');
    }

    public function testAllActionsRequireCsrfAndMissingUserIsNotFound(): void
    {
        $target = User::factory()->create();
        $this->actingAs($this->admin())->get('/admin/users/999999')->assertNotFound();
        $this->app->instance('env', 'local');
        $this->withMiddleware(PreventRequestForgery::class)->patch('/admin/users/'.$target->id.'/role', ['role' => 'admin', 'confirm_role' => 1])->assertStatus(419);
        $this->patch('/admin/users/'.$target->id.'/block', ['confirm_block' => 1])->assertStatus(419);
        $this->patch('/admin/users/'.$target->id.'/unblock', ['confirm_unblock' => 1])->assertStatus(419);
        $this->assertSame(UserRole::PARTICIPANT, $target->fresh()->role);
    }
}
