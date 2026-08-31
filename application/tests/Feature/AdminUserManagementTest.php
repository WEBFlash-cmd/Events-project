<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Enums\UserRole;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic feature test example.
     */
    public function testAdminCanBlockUser(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);

        $user = User::factory()->create();

        $response = $this
            ->actingAs($admin)
            ->patch("/api/admin/users/{$user->id}/block");

        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_blocked' => true,
        ]);
    }

    public function testParticipantCannotBlockUser(): void
    {
        $participant = User::factory()->create([
            'role' => UserRole::PARTICIPANT,
        ]);
        $user = User::factory()->create();
        $response = $this
            ->actingAs($participant)
            ->patch("/api/admin/users/{$user->id}/block");
        $response->assertStatus(403);
        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
            'is_blocked' => true,
        ]);
    }

    public function testAdminCanUnblockUser(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
        $user = User::factory()->create([
            'is_blocked' => true,
        ]);
        $response = $this
            ->actingAs($admin)
            ->patch("/api/admin/users/{$user->id}/unblock");

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_blocked' => false,
        ]);
    }

    public function testParticipantCannotUnblockUser(): void
    {
        $participant = User::factory()->create([
            'role' => UserRole::PARTICIPANT,
        ]);
        $user = User::factory()->create([
            'is_blocked' => true,
        ]);
        $response = $this
            ->actingAs($participant)
            ->patch("/api/admin/users/{$user->id}/unblock");

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_blocked' => true,
        ]);
    }

    public function testAdminCanChangeUserRole(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
        $user = User::factory()->create([
            'role' => UserRole::PARTICIPANT,
        ]);
        $response = $this
            ->actingAs($admin)
            ->patch("/api/admin/users/{$user->id}/role", [
                'role' => UserRole::ORGANIZER->value,
            ]);
        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => UserRole::ORGANIZER->value,
        ]);
    }

    public function testAdminCannotSetInvalidUserRole(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
        $user = User::factory()->create([
            'role' => UserRole::PARTICIPANT,
        ]);
        $response = $this
            ->actingAs($admin)
            ->patch("/api/admin/users/{$user->id}/role", [
                'role' => 'superadmin',
            ]);
        $response->assertStatus(422);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => UserRole::PARTICIPANT->value,
        ]);
    }
    public function testParticipantCannotChangeUserRole(): void
    {
        $participant = User::factory()->create([
            'role' => UserRole::PARTICIPANT,
        ]);
        $user = User::factory()->create([
            'role' => UserRole::PARTICIPANT,
        ]);
        $response = $this
            ->actingAs($participant)
            ->patch("/api/admin/users/{$user->id}/role", [
                'role' => UserRole::ORGANIZER->value,
            ]);

        $response->assertStatus(403);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => UserRole::PARTICIPANT->value,
        ]);
    }
    public function testAdminCanViewUsersList(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
        User::factory()->count(3)->create();

        $response = $this
            ->actingAs($admin)
            ->get("/api/admin/users");

        $response->assertStatus(200);

        $response->assertJsonCount(4, 'data');
    }
    public function testParticipantCannotViewUsersList(): void
    {
        $participant = User::factory()->create([
            'role' => UserRole::PARTICIPANT,
        ]);

        $response = $this
            ->actingAs($participant)
            ->get('/api/admin/users');

        $response->assertStatus(403);
    }
    public function testAdminUsersListIsPaginated(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);

        User::factory()->count(20)->create();

        $response = $this
            ->actingAs($admin)
            ->get('/api/admin/users');

        $response->assertStatus(200);

        $response->assertJsonCount(15, 'data');

        $response->assertJsonPath('per_page', 15);
        $response->assertJsonPath('total', 21);
    }
}
