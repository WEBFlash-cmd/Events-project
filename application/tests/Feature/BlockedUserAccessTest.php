<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\PaymentSignature;
use App\Services\UserAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class BlockedUserAccessTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    private function requestWithToken(string $method, string $uri, string $token, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeader('Authorization', 'Bearer '.$token)->json($method, $uri, $data);
    }

    public function testBlockingRevokesAllOwnTokensButNotOtherUsersTokens(): void
    {
        $user = User::factory()->create();
        $phone = $user->createToken('phone')->plainTextToken;
        $laptop = $user->createToken('laptop')->plainTextToken;
        $other = User::factory()->create();
        $otherToken = $other->createToken('phone')->plainTextToken;
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $adminToken = $admin->createToken('admin')->plainTextToken;
        $this->requestWithToken('PATCH', "/api/admin/users/{$user->id}/block", $adminToken)->assertOk();
        $this->assertTrue($user->fresh()->is_blocked);
        $this->assertNotSame($user->remember_token, $user->fresh()->remember_token);
        $this->assertSame(0, $user->tokens()->count());
        $this->requestWithToken('GET', '/api/me', $phone)->assertUnauthorized();
        $this->requestWithToken('GET', '/api/orders', $laptop)->assertUnauthorized();
        $this->requestWithToken('GET', '/api/me', $otherToken)->assertOk();
        $this->requestWithToken('GET', '/api/me', $adminToken)->assertOk();
    }

    public function testUnblockingRequiresFreshLoginAndDoesNotRestoreOldTokens(): void
    {
        $user = User::factory()->create();
        $oldToken = $user->createToken('phone')->plainTextToken;
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $adminToken = $admin->createToken('admin')->plainTextToken;
        $this->requestWithToken('PATCH', "/api/admin/users/{$user->id}/block", $adminToken)->assertOk();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertForbidden();
        $this->requestWithToken('PATCH', "/api/admin/users/{$user->id}/unblock", $adminToken)->assertOk();
        $this->assertFalse($user->fresh()->is_blocked);
        $this->requestWithToken('GET', '/api/me', $oldToken)->assertUnauthorized();
        $newToken = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->json('token');
        $this->requestWithToken('GET', '/api/me', $newToken)->assertOk();
    }

    public static function protectedRoutes(): array
    {
        return [['GET', '/api/me'], ['GET', '/api/user'], ['GET', '/api/orders'], ['GET', '/api/tickets'],
            ['GET', '/api/admin/users'], ['GET', '/api/admin/orders'], ['GET', '/api/admin/events'],
            ['POST', '/api/events'], ['PATCH', '/api/me'], ['PATCH', '/api/me/password'], ['POST', '/api/auth/logout']];
    }

    #[DataProvider('protectedRoutes')]
    public function testBlockedUserWithValidLegacyTokenCannotUseProtectedRoutes(string $method, string $uri): void
    {
        $user = User::factory()->create(['is_blocked' => true]);
        $token = $user->createToken('legacy')->plainTextToken;
        $this->requestWithToken($method, $uri, $token)->assertForbidden()->assertJsonPath('message', 'Your account is blocked');
    }

    public function testCachedAuthenticatedUserCannotBypassFreshBlockedFlag(): void
    {
        $user = User::factory()->create(['is_blocked' => false]);
        $this->actingAs($user);
        User::whereKey($user->id)->update(['is_blocked' => true]);
        $this->assertFalse($user->is_blocked);
        $this->getJson('/api/me')->assertForbidden()->assertJsonPath('message', 'Your account is blocked');
    }

    public function testBlockingRemovesDatabaseSessionsOnlyForTargetUser(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $other = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'blocked-phone', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'blocked-laptop', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'other-phone', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
        ]);
        app(UserAccessService::class)->block($user);
        $this->assertDatabaseMissing('sessions', ['id' => 'blocked-phone']);
        $this->assertDatabaseMissing('sessions', ['id' => 'blocked-laptop']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-phone']);
    }

    public function testBlockedAdminCannotUnblockThemselves(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN, 'is_blocked' => true]);
        $token = $admin->createToken('legacy')->plainTextToken;
        $this->requestWithToken('PATCH', "/api/admin/users/{$admin->id}/unblock", $token)
            ->assertForbidden()->assertJsonPath('message', 'Your account is blocked');
        $this->assertTrue($admin->fresh()->is_blocked);
    }

    public function testUnauthorizedBlockDoesNotDeleteTargetTokens(): void
    {
        $participant = User::factory()->create();
        $target = User::factory()->create();
        $targetToken = $target->createToken('phone')->plainTextToken;
        $actorToken = $participant->createToken('phone')->plainTextToken;
        $this->requestWithToken('PATCH', "/api/admin/users/{$target->id}/block", $actorToken)->assertForbidden();
        $this->assertFalse($target->fresh()->is_blocked);
        $this->requestWithToken('GET', '/api/me', $targetToken)->assertOk();
    }

    public function testRepeatedBlockRevokesAnyRemainingTokens(): void
    {
        $user = User::factory()->create();
        $access = app(UserAccessService::class);
        $access->block($user);
        $user->createToken('legacy');
        $access->block($user);
        $this->assertTrue($user->fresh()->is_blocked);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function testFailedTransactionRestoresStatusAndTokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('phone');
        try {
            DB::transaction(function () use ($user) {
                app(UserAccessService::class)->block($user);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertFalse($user->fresh()->is_blocked);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame($user->remember_token, $user->fresh()->remember_token);
    }

    public function testPublicCatalogRemainsAccessibleWithBlockedBearerToken(): void
    {
        $this->createOrderContext();
        $user = User::factory()->create(['is_blocked' => true]);
        $token = $user->createToken('legacy')->plainTextToken;
        $this->requestWithToken('GET', '/api/events', $token)->assertOk()->assertJsonPath('total', 1);
    }

    public function testSignedWebhookStillProcessesPriorPaymentForBlockedBuyer(): void
    {
        Queue::fake();
        $this->configurePaymentGateway();
        [$buyer, $event, $type] = $this->createOrderContext('1.00');
        $order = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id,
            'total_amount' => '1.00', 'status' => 'pending', 'expires_at' => now()->addMinutes(15)]);
        $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => 1, 'unit_price' => '1.00']);
        $type->decrement('quantity');
        $payment = app(PaymentService::class)->prepare($order);
        app(UserAccessService::class)->block($buyer);
        $payload = ['order_id' => $payment->reference, 'amount' => 100, 'status' => 1];
        $this->withHeader('X-Signature', app(PaymentSignature::class)->sign($payload, 'test-secret'))
            ->postJson('/api/payments/webhook', $payload)->assertOk();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $order->tickets()->count());
        $this->assertTrue($buyer->fresh()->is_blocked);
    }
}
