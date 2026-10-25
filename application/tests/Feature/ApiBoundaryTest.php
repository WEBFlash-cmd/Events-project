<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function testInvalidResourceIdentifiersReturnNotFound(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));

        foreach ([
            ['GET', '/api/events/invalid'],
            ['GET', '/api/orders/invalid'],
            ['GET', '/api/admin/categories/invalid'],
            ['PATCH', '/api/admin/users/invalid/block'],
            ['PATCH', '/api/events/1/ticket-types/invalid'],
        ] as [$method, $uri]) {
            $this->json($method, $uri)->assertNotFound();
        }
    }

    public function testRegistrationRejectsPasswordsUnsupportedByHasher(): void
    {
        foreach ([str_repeat('a', 73), str_repeat('я', 37), "password\0invalid"] as $password) {
            $this->postJson('/api/auth/register', [
                'email' => 'boundary@example.test', 'password' => $password,
            ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function testVenueCapacityCannotOverflowDatabaseInteger(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));

        $this->postJson('/api/admin/venues', [
            'name' => 'Large hall', 'address' => 'Tashkent', 'capacity' => 2147483648,
        ])->assertUnprocessable()->assertJsonValidationErrors(['capacity']);

        $this->assertDatabaseCount('venues', 0);
    }

    public function testLoginAcceptsUppercaseRegisteredEmail(): void
    {
        $user = User::factory()->create(['email' => 'boundary@example.test']);

        $this->postJson('/api/auth/login', [
            'email' => strtoupper($user->email), 'password' => 'password',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function testChangingEmailCaseCannotBypassLoginRateLimit(): void
    {
        $user = User::factory()->create(['email' => 'boundary@example.test']);
        foreach (['boundary@example.test', 'Boundary@example.test', 'BOUNDARY@example.test'] as $email) {
            $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong'])
                ->assertUnprocessable();
        }
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertTooManyRequests();
        $this->assertEquals(0, $user->tokens()->count());
    }
}
