<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase, WithFaker;
    public function testSuccessfulLogin()
    {
        $user = User::factory()->create();
        $response = $this->post("/api/auth/login", [
            'email' => $user->email,
            'password' => "password",
        ]);
        $response->assertStatus(200);
        $response->assertJsonStructure(["token"]);
    }

    public function testEmptyFields()
    {
        $user = User::factory()->create();
        $response = $this->post("/api/auth/login", [
            'email' => "",
            'password' => "",
        ]);
        $response->assertStatus(422);
    }

    public function testInvalidPassword()
    {
        $user = User::factory()->create();
        $response = $this->post("/api/auth/login", [
            'email' => $user->email,
            'password' => "password12345",
        ]);
        $response->assertStatus(422);
        $response->assertOnlyJsonValidationErrors(["email"]);
    }

    public function testInvalidEmail()
    {
        $user = User::factory()->create();
        $response = $this->post("/api/auth/login", [
            'email' => $this->faker->email(),
            'password' => "password",
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(["email"]);

        $response = $this->post("/api/auth/login", [
            'email' => 'mmmmm',
            'password' => "password",
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(["email"]);
    }

    public function testBruteforceLoginProtection()
    {
        $user = User::factory()->create();
        for ($i = 1; $i <= 4; $i++) {
            $response = $this->post("/api/auth/login", [
                'email' => $user->email,
                'password' => "password123",
            ]);
            $response->assertStatus($i < 4 ? 422 : 429);
        }
    }

    public function testBlockedUserCannotLogin()
    {
        $user = User::factory()->create([
            'is_blocked' => true,
        ]);
        $response = $this->post("/api/auth/login", [
            'email' => $user->email,
            'password' => "password",
        ]);
        $response->assertStatus(403);
    }
}
