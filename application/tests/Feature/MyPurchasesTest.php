<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class MyPurchasesTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    private User $buyer;

    private Event $event;

    private TicketType $ticketType;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->buyer, $this->event, $this->ticketType] = $this->createOrderContext();
    }

    public static function lists(): array
    {
        return ['orders' => ['orders'], 'tickets' => ['tickets']];
    }

    #[DataProvider('lists')]
    public function testListsContainOnlyOwnPurchasesNewestFirst(string $resource): void
    {
        $this->freezeTime();
        $first = $this->purchase($this->buyer);
        $second = $this->purchase($this->buyer);
        $this->purchase(User::factory()->create());
        Sanctum::actingAs($this->buyer);

        $expected = $resource === 'orders' ? [$second[0]->id, $first[0]->id] : [$second[1]->id, $first[1]->id];
        $response = $this->getJson("/api/{$resource}")->assertOk()
            ->assertJsonPath('total', 2)->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event.title', $this->event->title)
            ->assertJsonPath('data.0.event.venue.name', 'Hall');

        $this->assertSame($expected, array_column($response->json('data'), 'id'));
        if ($resource === 'orders') {
            $response->assertJsonPath('data.0.payment.status', 'paid')->assertJsonCount(1, 'data.0.items');
        } else {
            $response->assertJsonPath('data.0.ticket_type.name', 'Standard')
                ->assertJsonPath('data.0.order.status', 'paid')
                ->assertJsonMissingPath('data.0.order.payment');
        }
    }

    #[DataProvider('lists')]
    public function testListsArePaginatedAndDoNotCountOtherUsers(string $resource): void
    {
        $this->freezeTime();
        $purchases = [];
        for ($index = 0; $index < 16; $index++) {
            $purchases[] = $this->purchase($this->buyer);
        }
        $this->purchase(User::factory()->create());
        Sanctum::actingAs($this->buyer);
        $column = $resource === 'orders' ? 0 : 1;

        $this->getJson("/api/{$resource}")->assertOk()
            ->assertJsonPath('total', 16)->assertJsonPath('per_page', 15)
            ->assertJsonPath('last_page', 2)->assertJsonCount(15, 'data');
        $this->getJson("/api/{$resource}?page=2")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $purchases[0][$column]->id);
        $custom = $this->getJson("/api/{$resource}?per_page=2&page=2")->assertOk()
            ->assertJsonPath('total', 16)->assertJsonPath('current_page', 2)
            ->assertJsonPath('per_page', 2)->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $purchases[13][$column]->id);
        $this->assertStringContainsString('per_page=2', $custom->json('next_page_url'));
    }

    #[DataProvider('lists')]
    public function testEmptyAndOutOfRangePagesReturnEmptyData(string $resource): void
    {
        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/{$resource}")->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
        $this->purchase($this->buyer);
        $this->getJson("/api/{$resource}?page=50")->assertOk()
            ->assertJsonPath('total', 1)->assertJsonCount(0, 'data');
    }

    public function testOrdersIncludePendingAndCancelledHistoryWithoutInventingTickets(): void
    {
        $this->purchase($this->buyer, 'pending');
        $this->purchase($this->buyer, 'cancelled');
        Sanctum::actingAs($this->buyer);
        $this->getJson('/api/orders')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/tickets')->assertOk()->assertJsonPath('total', 0);
    }

    public function testOwnerCanReadTicketEvenAfterEventIsCancelled(): void
    {
        [, $ticket] = $this->purchase($this->buyer);
        $this->event->update(['status' => 'cancelled']);
        Sanctum::actingAs($this->buyer);

        $this->getJson("/api/tickets/{$ticket->id}")->assertOk()
            ->assertJsonPath('data.id', $ticket->id)->assertJsonPath('data.number', $ticket->number)
            ->assertJsonPath('data.event.status', 'cancelled')
            ->assertJsonPath('data.order.status', 'paid');
    }

    public function testAnotherParticipantAndEventOrganizerCannotReadTicket(): void
    {
        [$order, $ticket] = $this->purchase($this->buyer);
        foreach ([User::factory()->create(), User::findOrFail($this->event->organizer_id)] as $otherUser) {
            Sanctum::actingAs($otherUser);
            $this->getJson("/api/tickets/{$ticket->id}")->assertForbidden();
            $this->getJson("/api/orders/{$order->id}")->assertForbidden();
            $this->getJson('/api/tickets')->assertOk()->assertJsonPath('total', 0);
            $this->getJson('/api/orders')->assertOk()->assertJsonPath('total', 0);
        }
    }

    public function testAdminCanViewTicketButPersonalListsRemainScoped(): void
    {
        [$order, $ticket] = $this->purchase($this->buyer);
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $this->getJson("/api/tickets/{$ticket->id}")->assertOk();
        $this->getJson("/api/orders/{$order->id}")->assertOk();
        $this->getJson('/api/tickets')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/orders')->assertOk()->assertJsonPath('total', 0);
    }

    public function testGuestsCannotReadPurchases(): void
    {
        [$order, $ticket] = $this->purchase($this->buyer);
        foreach (['/api/orders', '/api/tickets', "/api/orders/{$order->id}", "/api/tickets/{$ticket->id}"] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public function testMissingTicketReturnsNotFound(): void
    {
        Sanctum::actingAs($this->buyer);
        foreach (['999999', 'not-a-number', (string) Str::uuid()] as $id) {
            $this->getJson("/api/tickets/{$id}")->assertNotFound();
        }
    }

    public static function invalidPagination(): array
    {
        $cases = [];
        foreach (['orders', 'tickets'] as $resource) {
            foreach ([
                'page=0' => 'page', 'page=-1' => 'page', 'page=hello' => 'page',
                'page[]=1' => 'page', 'per_page=0' => 'per_page',
                'per_page=101' => 'per_page', 'per_page=1.5' => 'per_page', 'user_id=999' => 'user_id',
            ] as $query => $field) {
                $cases["{$resource}: {$query}"] = [$resource, $query, $field];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidPagination')]
    public function testInvalidPaginationAndOwnerOverrideAreRejected(string $resource, string $query, string $field): void
    {
        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/{$resource}?{$query}")->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    private function purchase(User $owner, string $status = 'paid'): array
    {
        $order = Order::create([
            'user_id' => $owner->id, 'event_id' => $this->event->id,
            'total_amount' => '100000.00', 'status' => $status, 'expires_at' => now()->addMinutes(15),
        ]);
        $item = $order->items()->create([
            'ticket_type_id' => $this->ticketType->id, 'quantity' => 1, 'unit_price' => '100000.00',
        ]);
        $order->payment()->create([
            'reference' => (string) Str::uuid(), 'cashbox_id' => 1, 'amount' => 10000000,
            'status' => $status === 'paid' ? 'paid' : 'pending',
        ]);
        $ticket = $status === 'paid' ? $order->tickets()->create([
            'number' => (string) Str::uuid(), 'user_id' => $owner->id, 'event_id' => $this->event->id,
            'ticket_type_id' => $this->ticketType->id, 'order_item_id' => $item->id,
            'sequence' => 1, 'status' => 'active',
        ]) : null;

        return [$order, $ticket];
    }
}
