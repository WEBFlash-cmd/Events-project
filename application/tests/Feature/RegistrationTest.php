<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Str;
use Tests\TestCase;
use App\Enums\UserRole;

class RegistrationTest extends TestCase
{
    use WithFaker;
    use RefreshDatabase;
    /**
     * A basic feature test example.
     */
    public function testSuccessRegistration(): void
    {
        \Event::fake();
        $email = $this->faker->email();
        $password = "123456";
        $response = $this->post('/api/auth/register', [
            "email" => $email,
            "password" => $password
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', ["email" => $email]);
        $user = User::where("email", $email)->first();
        $this->assertEquals(UserRole::PARTICIPANT, $user->role);
        $this->assertFalse($user->is_blocked);
        $this->assertTrue(\Hash::check($password, $user->password));
        $this->assertEquals(Str::lower($email), $user->email);
        \Event::assertDispatched(Registered::class);
    }

    public function testInvalidRegistrationData(): void
    {
        $resp = $this->post('/api/auth/register', [
            "email" => "",
            "password" => ""
        ]);
        $resp->assertStatus(422);

        $resp = $this->post('/api/auth/register', [
            "email" => $this->faker->email(),
            "password" => ""
        ]);
        $resp->assertStatus(422);

        $resp = $this->post('/api/auth/register', [
            "email" => $this->faker->name,
            "password" => "123456"
        ]);
        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(["email"]);
    }

    public function testDuplicateRegistrationData(): void
    {
        $user = User::factory()->create();
        $resp = $this->post('/api/auth/register', [
            "email" => $user->email,
            "password" => "123456"
        ]);
        $resp->assertStatus(422);

        $resp = $this->post('/api/auth/register', [
            "email" => Str::upper($user->email),
            "password" => "123456"
        ]);
        $resp->assertStatus(422);
    }

    public function testSingleIPRegistrationRestriction(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $resp = $this->withServerVariables([
                "REMOTE_ADDR" => "3.3.3.3"
            ])->post('/api/auth/register', [
                "email" => $this->faker->email(),
                "password" => "123456"
            ]);
            $resp->assertStatus($i < 10 ? 201 : 429);
        }
    }
}
