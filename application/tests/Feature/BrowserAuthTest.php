<?php

namespace Tests\Feature;

use App\Mail\EmailVerification;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BrowserAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
    }

    public function testGuestPagesAndAccountProtection(): void
    {
        foreach (['/login', '/register', '/verify-email'] as $url) {
            $this->get($url)->assertOk()->assertSee('csrf-token');
        }
        $this->get('/account')->assertRedirect('/login');
        $this->getJson('/account/profile')->assertUnauthorized();
        $this->patchJson('/account/profile', ['name' => 'Guest'])->assertUnauthorized();
        $this->patchJson('/account/password', [])->assertUnauthorized();
    }

    public function testRegistrationCreatesParticipantSessionAndSendsVerificationWithoutToken(): void
    {
        $this->postJson('/register', [
            'email' => 'NEW@example.test', 'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123', 'role' => 'admin',
        ])->assertCreated()->assertJsonPath('redirect', route('verification'));
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame('participant', $user->rbacRole->name);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(0, $user->tokens()->count());
        Mail::assertSent(EmailVerification::class);
        $this->get('/account')->assertOk()->assertSee('Email ещё не подтверждён');
    }

    public function testBrowserRegistrationRequiresStrongEnoughMatchingPassword(): void
    {
        $this->postJson('/register', ['email' => 'new@example.test', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/register', ['email' => 'new@example.test', 'password' => 'new-secret-123', 'password_confirmation' => 'different'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
        Mail::assertNothingSent();
    }

    public function testBrowserRegistrationRejectsMissingOrMalformedConfirmationBeforeCreatingUser(): void
    {
        $payload = ['email' => 'confirmation@example.test', 'password' => 'new-secret-123'];
        $this->postJson('/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('password_confirmation');
        $this->postJson('/register', array_merge($payload, ['password_confirmation' => ['new-secret-123']]))
            ->assertUnprocessable()->assertJsonValidationErrors('password_confirmation');
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
        $this->assertGuest('web');
        Mail::assertNothingSent();
    }

    public function testLoginUsesSessionAndLogoutEndsIt(): void
    {
        $user = User::factory()->create();
        $this->postJson('/login', ['email' => strtoupper($user->email), 'password' => 'password'])
            ->assertOk()->assertJsonPath('redirect', route('account'))
            ->assertSessionHas('password_hash_web', $user->getAuthPassword());
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(0, $user->tokens()->count());
        $this->get('/login')->assertRedirect('/account');
        $this->get('/account')->assertOk()->assertSee('Личный кабинет');
        $this->postJson('/logout')->assertNoContent();
        $this->assertGuest('web');
        $this->get('/account')->assertRedirect('/login');
    }

    public function testWrongCredentialsAndBlockedUserCannotLogin(): void
    {
        $user = User::factory()->create();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $user->forceFill(['is_blocked' => true])->save();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertForbidden();
        $this->assertGuest('web');
    }

    public function testBlockedSessionCannotUseAccountButCanLogout(): void
    {
        $user = User::factory()->create(['is_blocked' => true]);
        $this->actingAs($user, 'web')->get('/account')->assertForbidden();
        $this->patchJson('/account/profile', ['name' => 'Changed'])->assertForbidden();
        $this->postJson('/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function testProfileChangeUsesExistingPasswordAndVerificationRules(): void
    {
        $user = User::factory()->create(['name' => 'Ali']);
        $this->actingAs($user, 'web')->patchJson('/account/profile', ['email' => 'new@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->patchJson('/account/profile', [
            'name' => 'Alisher', 'email' => 'new@example.test', 'current_password' => 'password',
        ])->assertOk()->assertJsonPath('data.name', 'Alisher')->assertJsonPath('data.email_verified_at', null);
        Mail::assertSent(EmailVerification::class);
    }

    public function testPasswordChangeRevokesTokensAndCurrentSession(): void
    {
        $user = User::factory()->create();
        $user->createToken('phone');
        $this->actingAs($user, 'web')->patchJson('/account/password', [
            'current_password' => 'password', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123',
        ])->assertNoContent();
        $this->assertGuest('web');
        $this->assertTrue(Hash::check('new-secret-123', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/login', ['email' => $user->email, 'password' => 'new-secret-123'])->assertOk();
    }

    public function testSessionWithOldPasswordHashIsRejected(): void
    {
        $user = User::factory()->create();
        $oldHash = $user->password;
        $user->update(['password' => 'changed-secret-123']);
        $this->actingAs($user->fresh(), 'web')->withSession(['password_hash_web' => $oldHash])
            ->getJson('/account/profile')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function testVerificationAcceptsValidCodeOnceAndRejectsExpiredCode(): void
    {
        $user = User::factory()->unverified()->create();
        Cache::put('mail-Ab12Cd', ['user_id' => $user->id, 'email' => $user->email], now()->addHour());
        $this->postJson('/verify-email', ['code' => 'Ab12Cd'])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->postJson('/verify-email', ['code' => 'Ab12Cd'])->assertUnprocessable();
        Cache::put('mail-expired', ['user_id' => $user->id, 'email' => $user->email], now()->subSecond());
        $this->postJson('/verify-email', ['code' => 'expired'])->assertUnprocessable();
    }

    public function testAccountEscapesUserData(): void
    {
        $user = User::factory()->create(['name' => '"><script>alert(1)</script>']);
        $this->actingAs($user)->get('/account')->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public static function csrfEndpoints(): array
    {
        return [['POST', '/login'], ['POST', '/register'], ['POST', '/verify-email'],
            ['POST', '/logout'], ['PATCH', '/account/profile'], ['PATCH', '/account/password']];
    }

    #[DataProvider('csrfEndpoints')]
    public function testBrowserMutationsRequireCsrf(string $method, string $url): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests() { return false; }
        });
        $this->json($method, $url)->assertStatus(419);
    }
}
