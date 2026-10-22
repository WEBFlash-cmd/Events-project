<?php

namespace Tests\Feature;

use App\Mail\EmailVerification;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function requestWithToken(string $method, string $uri, string $token, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeader('Authorization', 'Bearer '.$token)->json($method, $uri, $data);
    }

    public function testCurrentProfileOnlyContainsPublicAccountFields(): void
    {
        $user = User::factory()->create(['name' => 'Ali']);
        $token = $user->createToken('phone')->plainTextToken;
        $response = $this->requestWithToken('GET', '/api/me', $token)->assertOk()
            ->assertJsonPath('data.id', $user->id)->assertJsonPath('data.name', 'Ali');
        $this->assertSame(['id', 'name', 'email', 'email_verified_at'], array_keys($response->json('data')));
        $this->assertStringNotContainsString($user->password, $response->getContent());
    }

    public function testLogoutRevokesOnlyCurrentDeviceToken(): void
    {
        $user = User::factory()->create();
        $phone = $user->createToken('phone')->plainTextToken;
        $laptop = $user->createToken('laptop')->plainTextToken;
        $this->requestWithToken('POST', '/api/auth/logout', $phone)->assertNoContent();
        $this->requestWithToken('GET', '/api/me', $phone)->assertUnauthorized();
        $this->requestWithToken('POST', '/api/auth/logout', $phone)->assertUnauthorized();
        $this->requestWithToken('GET', '/api/me', $laptop)->assertOk();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function testNameUpdateOnlyAffectsAuthenticatedUser(): void
    {
        $user = User::factory()->create(['name' => 'Ali']);
        $other = User::factory()->create(['name' => 'Vali']);
        $token = $user->createToken('phone')->plainTextToken;
        $this->requestWithToken('PATCH', '/api/me', $token, ['name' => 'Alisher'])->assertOk()
            ->assertJsonPath('data.name', 'Alisher');
        $this->assertSame('Vali', $other->fresh()->name);
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at);
        Mail::assertNothingSent();
    }

    public function testEmailChangeRequiresPasswordAndNewVerification(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;
        $this->requestWithToken('PATCH', '/api/me', $token, [
            'email' => '  NEW@EXAMPLE.COM  ', 'current_password' => 'password',
        ])->assertOk()->assertJsonPath('data.email', 'new@example.com')->assertJsonPath('data.email_verified_at', null);
        Mail::assertSent(EmailVerification::class, function ($mail) use ($user) {
            $mail->assertTo('new@example.com');
            $this->assertSame(['user_id' => $user->id, 'email' => 'new@example.com'], Cache::get('mail-'.$mail->code));
            $this->postJson('/api/auth/verify', ['code' => $mail->code])->assertOk();
            $this->postJson('/api/auth/verify', ['code' => $mail->code])->assertUnprocessable();
            return true;
        });
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function testUnchangedEmailDoesNotRequirePasswordOrNewVerification(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;
        $this->requestWithToken('PATCH', '/api/me', $token, ['email' => strtoupper($user->email)])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
        Mail::assertNothingSent();
    }

    public static function wrongCurrentPasswords(): array
    {
        return ['missing' => [[]], 'incorrect' => [['current_password' => 'wrong-password']]];
    }

    #[DataProvider('wrongCurrentPasswords')]
    public function testEmailChangeWithoutCorrectPasswordIsAtomic(array $extra): void
    {
        $user = User::factory()->create(['name' => 'Ali']);
        $token = $user->createToken('phone')->plainTextToken;
        $this->requestWithToken('PATCH', '/api/me', $token, $extra + ['email' => 'new@example.com', 'name' => 'Changed'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertSame('Ali', $user->fresh()->name);
        Mail::assertNothingSent();
    }

    public function testDuplicateEmailIsRejectedAfterNormalization(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;
        $this->requestWithToken('PATCH', '/api/me', $token, ['email' => strtoupper($other->email), 'current_password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public static function protectedFields(): array
    {
        return [['id', 999], ['user_id', 999], ['role', 'admin'], ['role_id', 1],
            ['is_blocked', true], ['email_verified_at', '2026-01-01'], ['password', 'secret-password']];
    }

    #[DataProvider('protectedFields')]
    public function testProfileCannotChangeProtectedFields(string $field, mixed $value): void
    {
        $user = User::factory()->create(['name' => 'Ali']);
        $token = $user->createToken('phone')->plainTextToken;
        $this->requestWithToken('PATCH', '/api/me', $token, ['name' => 'Changed', $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame('Ali', $user->fresh()->name);
    }

    public static function invalidProfiles(): array
    {
        return [[[]], [['name' => '']], [['name' => str_repeat('a', 256)]],
            [['email' => 'not-email']], [['email' => ['invalid']]]];
    }

    #[DataProvider('invalidProfiles')]
    public function testInvalidProfileIsRejected(array $data): void
    {
        $user = User::factory()->create();
        $this->requestWithToken('PATCH', '/api/me', $user->createToken('phone')->plainTextToken, $data)->assertUnprocessable();
    }

    public function testPasswordChangeRevokesAllOwnTokensAndAllowsNewLogin(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $phone = $user->createToken('phone')->plainTextToken;
        $laptop = $user->createToken('laptop')->plainTextToken;
        $otherToken = $other->createToken('phone')->plainTextToken;
        $this->requestWithToken('PATCH', '/api/me/password', $phone, [
            'current_password' => 'password', 'password' => 'new-secret-456', 'password_confirmation' => 'new-secret-456',
        ])->assertNoContent();
        $this->assertTrue(Hash::check('new-secret-456', $user->fresh()->password));
        $this->assertNotSame($user->remember_token, $user->fresh()->remember_token);
        $this->assertSame(0, $user->tokens()->count());
        $this->requestWithToken('GET', '/api/me', $phone)->assertUnauthorized();
        $this->requestWithToken('GET', '/api/me', $laptop)->assertUnauthorized();
        $this->requestWithToken('GET', '/api/me', $otherToken)->assertOk();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'new-secret-456'])->assertOk();
    }

    public static function invalidPasswords(): array
    {
        return [['wrong-current', 'new-secret-456', 'new-secret-456'],
            ['password', 'short', 'short'], ['password', 'new-secret-456', 'mismatch'],
            ['password', 'password', 'password'], ['password', str_repeat('x', 73), str_repeat('x', 73)],
            ['password', str_repeat('я', 40), str_repeat('я', 40)], ['password', "secret\0password", "secret\0password"]];
    }

    #[DataProvider('invalidPasswords')]
    public function testInvalidPasswordChangeLeavesPasswordAndTokenValid(string $current, string $password, string $confirmation): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;
        $this->requestWithToken('PATCH', '/api/me/password', $token, [
            'current_password' => $current, 'password' => $password, 'password_confirmation' => $confirmation,
        ])->assertUnprocessable();
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->requestWithToken('GET', '/api/me', $token)->assertOk();
    }

    public function testStaleUserCannotChangePasswordUsingOldCredentials(): void
    {
        $user = User::factory()->create();
        User::whereKey($user->id)->update(['password' => Hash::make('already-changed')]);
        try {
            app(AccountService::class)->updatePassword($user, ['current_password' => 'password', 'password' => 'new-secret-456']);
            $this->fail('Current database credentials must be checked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('current_password', $exception->errors());
        }
        $this->assertTrue(Hash::check('already-changed', $user->fresh()->password));
    }

    public function testOldVerificationCannotVerifyNewHolderOfPreviousEmail(): void
    {
        $user = User::factory()->unverified()->create();
        Cache::put('mail-old-code', ['user_id' => $user->id, 'email' => $user->email]);
        $oldEmail = $user->email;
        $user->update(['email' => 'changed@example.com']);
        $newHolder = User::factory()->unverified()->create(['email' => $oldEmail]);
        $this->postJson('/api/auth/verify', ['code' => 'old-code'])->assertUnprocessable();
        $this->assertNull($newHolder->fresh()->email_verified_at);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public static function guestEndpoints(): array
    {
        return [['GET', '/api/me'], ['PATCH', '/api/me'],
            ['PATCH', '/api/me/password'], ['POST', '/api/auth/logout']];
    }

    #[DataProvider('guestEndpoints')]
    public function testGuestsCannotUseAccountEndpoints(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }
}
