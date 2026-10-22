<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Order;
use App\Models\Permission;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class AdminPurchasesTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    private function purchase(User $user, Event $event, TicketType $type, string $status = 'paid', bool $refund = false): array
    {
        $order = Order::create(['user_id' => $user->id, 'event_id' => $event->id,
            'total_amount' => '1.00', 'status' => $status]);
        $item = $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => 1, 'unit_price' => '1.00']);
        $order->payment()->create(['reference' => (string) Str::uuid(), 'cashbox_id' => 1, 'amount' => 100,
            'status' => $refund ? 'refund_required' : ($status === 'paid' ? 'paid' : 'pending'),
            'refund_required' => $refund, 'payment_url' => 'https://payments.test/private-link']);
        $ticket = null;
        if ($status === 'paid') {
            $ticket = $order->tickets()->create(['number' => (string) Str::uuid(), 'user_id' => $user->id,
                'event_id' => $event->id, 'ticket_type_id' => $type->id, 'order_item_id' => $item->id,
                'sequence' => 1, 'status' => $refund ? 'cancelled' : 'active']);
            $ticket->refund_required = $refund;
            $ticket->save();
        }
        return [$order, $ticket];
    }

    public static function resources(): array
    {
        return [['orders'], ['tickets']];
    }

    #[DataProvider('resources')]
    public function testAdminListsAllBuyersAndEventsWithStablePagination(string $resource): void
    {
        $this->freezeTime();
        [$buyer, $event, $type] = $this->createOrderContext();
        $first = $this->purchase($buyer, $event, $type);
        $second = $this->purchase(User::factory()->create(), $event, $type);
        [$otherBuyer, $otherEvent, $otherType] = $this->createOrderContext();
        $last = $this->purchase($otherBuyer, $otherEvent, $otherType);
        $index = $resource === 'orders' ? 0 : 1;
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $response = $this->getJson("/api/admin/{$resource}?per_page=1")->assertOk()
            ->assertJsonPath('total', 3)->assertJsonPath('last_page', 3)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $last[$index]->id)
            ->assertJsonPath('data.0.user.email', $otherBuyer->email)
            ->assertJsonPath('data.0.event.id', $otherEvent->id);
        $this->assertSame(['id', 'name', 'email'], array_keys($response->json('data.0.user')));
        $this->assertStringNotContainsString('private-link', $response->getContent());
        $this->assertStringNotContainsString('cashbox_id', $response->getContent());
        $this->assertStringNotContainsString('remember_token', $response->getContent());
        $this->getJson("/api/admin/{$resource}?per_page=1&page=2")->assertOk()
            ->assertJsonPath('data.0.id', $second[$index]->id);
        $this->getJson("/api/admin/{$resource}?page=99")->assertOk()->assertJsonCount(0, 'data');
        $all = $this->getJson("/api/admin/{$resource}")->assertOk()->assertJsonPath('per_page', 15);
        $this->assertSame([$last[$index]->id, $second[$index]->id, $first[$index]->id], array_column($all->json('data'), 'id'));
    }

    #[DataProvider('resources')]
    public function testGuestsAndNonAdminsCannotListPurchases(string $resource): void
    {
        [$buyer, $event] = $this->createOrderContext();
        $this->getJson("/api/admin/{$resource}")->assertUnauthorized();
        foreach ([$buyer, $event->organizer] as $user) {
            Sanctum::actingAs($user);
            $this->getJson("/api/admin/{$resource}")->assertForbidden();
        }
    }

    #[DataProvider('resources')]
    public function testAdminStillNeedsSpecificPermission(string $resource): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $admin->rbacRole->permissions()->detach(Permission::where('name', "{$resource}.view-any")->value('id'));
        Sanctum::actingAs($admin);
        $this->getJson("/api/admin/{$resource}")->assertForbidden();
        $other = $resource === 'orders' ? 'tickets' : 'orders';
        $this->getJson("/api/admin/{$other}")->assertOk();
    }

    #[DataProvider('resources')]
    public function testFiltersCombineWithoutReturningOtherPurchases(string $resource): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $expected = $this->purchase($buyer, $event, $type);
        $this->purchase(User::factory()->create(), $event, $type);
        [, $otherEvent, $otherType] = $this->createOrderContext();
        $this->purchase($buyer, $otherEvent, $otherType);
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $status = $resource === 'orders' ? 'paid' : 'active';
        $index = $resource === 'orders' ? 0 : 1;
        $this->getJson("/api/admin/{$resource}?user_id={$buyer->id}&event_id={$event->id}&status={$status}")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $expected[$index]->id);
    }

    #[DataProvider('resources')]
    public function testRefundFilterSupportsBothTrueAndFalse(string $resource): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $normal = $this->purchase($buyer, $event, $type);
        $refund = $this->purchase($buyer, $event, $type, refund: true);
        $index = $resource === 'orders' ? 0 : 1;
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $this->getJson("/api/admin/{$resource}?refund_required=1")->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $refund[$index]->id);
        $this->getJson("/api/admin/{$resource}?refund_required=0")->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $normal[$index]->id);
    }

    public function testOrdersWithoutPaymentAreNotMarkedAsRequiringRefund(): void
    {
        [$buyer, $event] = $this->createOrderContext();
        $order = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id,
            'total_amount' => '1.00', 'status' => 'pending']);
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $this->getJson('/api/admin/orders?refund_required=0')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $order->id)->assertJsonPath('data.0.payment', null);
        $this->getJson('/api/admin/orders?refund_required=1')->assertOk()->assertJsonPath('total', 0);
    }

    public function testAllOrderStatusesAndHistoricalTicketsAreVisible(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        foreach (['pending', 'paid', 'cancelled', 'expired'] as $status) {
            $this->purchase($buyer, $event, $type, $status);
        }
        $this->purchase($buyer, $event, $type, refund: true);
        $event->update(['status' => 'cancelled']);
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $this->getJson('/api/admin/orders')->assertOk()->assertJsonPath('total', 5);
        $this->getJson('/api/admin/tickets')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/admin/orders?status=expired')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/admin/tickets?status=cancelled')->assertOk()->assertJsonPath('total', 1);
    }

    #[DataProvider('resources')]
    public function testPersonalListsStayPersonalEvenForAdmin(string $resource): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->purchase($buyer, $event, $type);
        $own = $this->purchase($admin, $event, $type);
        Sanctum::actingAs($admin);
        $this->getJson("/api/{$resource}")->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $own[$resource === 'orders' ? 0 : 1]->id);
        $this->getJson("/api/admin/{$resource}")->assertOk()->assertJsonPath('total', 2);
    }

    public static function invalidFilters(): array
    {
        $cases = [];
        foreach (['orders', 'tickets'] as $resource) {
            foreach (['page=0', 'page=abc', 'per_page=101', 'per_page=0', 'user_id=99999999',
                'event_id=99999999', 'status=unknown', 'refund_required=maybe', 'user_id[]=1'] as $query) {
                $cases["{$resource}: {$query}"] = [$resource, $query];
            }
        }
        return $cases;
    }

    #[DataProvider('invalidFilters')]
    public function testInvalidFiltersAreRejected(string $resource, string $query): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $this->getJson("/api/admin/{$resource}?{$query}")->assertUnprocessable();
    }

    #[DataProvider('resources')]
    public function testEmptyListsReturnPaginatedEmptyData(string $resource): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $this->getJson("/api/admin/{$resource}")->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
    }
}
