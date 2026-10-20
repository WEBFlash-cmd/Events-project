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

class OrganizerReportsTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    private function purchase(Event $event, TicketType $type, User $buyer, string $status = 'paid',
        int $quantity = 1, int $unitMinor = 105, bool $refund = false): Order
    {
        $minor = $quantity * $unitMinor;
        $format = fn ($value) => sprintf('%d.%02d', intdiv($value, 100), $value % 100);
        $order = Order::create(['event_id' => $event->id, 'user_id' => $buyer->id,
            'status' => $status, 'total_amount' => $format($minor), 'expires_at' => now()->addMinutes(15)]);
        $item = $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => $quantity,
            'unit_price' => $format($unitMinor)]);
        $order->payment()->create(['reference' => (string) Str::uuid(), 'cashbox_id' => 1,
            'amount' => $minor, 'status' => $refund ? 'refund_required' : ($status === 'paid' ? 'paid' : 'pending'),
            'refund_required' => $refund, 'payment_url' => 'http://payments.test/private-url']);
        if ($status === 'paid') {
            for ($sequence = 1; $sequence <= $quantity; $sequence++) {
                $ticket = $order->tickets()->create(['number' => (string) Str::uuid(),
                    'event_id' => $event->id, 'user_id' => $buyer->id, 'ticket_type_id' => $type->id,
                    'order_item_id' => $item->id, 'sequence' => $sequence,
                    'status' => $refund ? 'cancelled' : 'active']);
                if ($refund) {
                    $ticket->refund_required = true;
                    $ticket->save();
                }
            }
        }
        return $order;
    }

    public static function endpoints(): array
    {
        return ['participants' => ['participants'], 'sales' => ['sales']];
    }

    #[DataProvider('endpoints')]
    public function testOrganizerAndAdminCanReadButOthersCannot(string $endpoint): void
    {
        [$buyer, $event] = $this->createOrderContext();
        $url = "/api/events/{$event->id}/{$endpoint}";
        $this->getJson($url)->assertUnauthorized();
        foreach ([$buyer, User::factory()->create(['role' => UserRole::ORGANIZER])] as $actor) {
            Sanctum::actingAs($actor);
            $this->getJson($url)->assertForbidden();
        }
        foreach ([$event->organizer, User::factory()->create(['role' => UserRole::ADMIN])] as $actor) {
            Sanctum::actingAs($actor);
            $this->getJson($url)->assertOk();
        }
        $this->getJson("/api/events/9999999/{$endpoint}")->assertNotFound();
        $this->getJson("/api/events/not-a-number/{$endpoint}")->assertNotFound();
    }

    #[DataProvider('endpoints')]
    public function testOwnerRequiresReportPermission(string $endpoint): void
    {
        [, $event] = $this->createOrderContext();
        $actor = $event->organizer;
        $actor->rbacRole->permissions()->detach(Permission::where('name', 'events.reports')->value('id'));
        Sanctum::actingAs($actor);
        $this->getJson("/api/events/{$event->id}/{$endpoint}")->assertForbidden();
    }

    public function testParticipantsAreScopedPaginatedAndDoNotExposePrivateFields(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $order = $this->purchase($event, $type, $buyer, quantity: 2);
        $this->purchase($event, $type, $buyer, status: 'pending');
        [$otherBuyer, $otherEvent, $otherType] = $this->createOrderContext();
        $other = $this->purchase($otherEvent, $otherType, $otherBuyer);
        Sanctum::actingAs($event->organizer);
        $response = $this->getJson("/api/events/{$event->id}/participants?per_page=1")->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('per_page', 1)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->tickets()->max('id'))
            ->assertJsonPath('data.0.user.email', $buyer->email)
            ->assertJsonPath('data.0.ticket_type.name', $type->name);
        $row = $response->json('data.0');
        $this->assertSame(['id', 'email'], array_keys($row['user']));
        $this->assertArrayNotHasKey('payment', $row);
        $this->assertArrayNotHasKey('order', $row);
        $this->assertStringNotContainsString('private-url', $response->getContent());
        $this->assertStringNotContainsString($other->tickets()->first()->number, $response->getContent());
        $this->getJson("/api/events/{$event->id}/participants?per_page=1&page=2")->assertOk()
            ->assertJsonPath('data.0.id', $order->tickets()->min('id'));
        $this->getJson("/api/events/{$event->id}/participants?page=99")->assertOk()->assertJsonCount(0, 'data');
    }

    public function testParticipantFilterAndCancelledEventPreserveTicketHistory(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $order = $this->purchase($event, $type, $buyer, quantity: 2);
        $ticket = $order->tickets()->first();
        $ticket->status = 'used';
        $ticket->checked_in_at = now();
        $ticket->save();
        Sanctum::actingAs($event->organizer);
        $this->getJson("/api/events/{$event->id}/participants?status=used")->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ticket->id);
        $event->update(['status' => 'cancelled']);
        $this->getJson("/api/events/{$event->id}/participants")->assertOk()->assertJsonPath('total', 2);
    }

    public static function invalidFilters(): array
    {
        return [['page=0'], ['page=abc'], ['per_page=101'], ['per_page=0'], ['status=pending'],
            ['status='], ['user_id=1'], ['event_id=2']];
    }

    #[DataProvider('invalidFilters')]
    public function testInvalidParticipantFiltersAreRejected(string $query): void
    {
        [, $event] = $this->createOrderContext();
        Sanctum::actingAs($event->organizer);
        $this->getJson("/api/events/{$event->id}/participants?{$query}")->assertUnprocessable();
    }

    public function testSalesSeparateGrossReceiptsRefundsAndTicketsWithoutDoubleCounting(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $paid = $this->purchase($event, $type, $buyer, quantity: 2);
        $ticket = $paid->tickets()->first();
        $ticket->status = 'used';
        $ticket->checked_in_at = now();
        $ticket->save();
        $buyer2 = User::factory()->create();
        $this->purchase($event, $type, $buyer2, unitMinor: 199, refund: true);
        $this->purchase($event, $type, $buyer, status: 'pending');
        $this->purchase($event, $type, $buyer, status: 'cancelled');
        $this->purchase($event, $type, $buyer, status: 'expired');
        $this->purchase($event, $type, $buyer, status: 'cancelled', unitMinor: 77, refund: true);
        $type->update(['price' => '999.99']);
        $unusedType = $event->ticketTypes()->create(['name' => 'VIP', 'price' => '5.00', 'quantity' => 4]);
        [$otherBuyer, $otherEvent, $otherType] = $this->createOrderContext();
        $this->purchase($otherEvent, $otherType, $otherBuyer, unitMinor: 99999);
        Sanctum::actingAs($event->organizer);
        $this->getJson("/api/events/{$event->id}/sales")->assertOk()
            ->assertJsonPath('data.event_id', $event->id)
            ->assertJsonPath('data.orders', ['pending' => 1, 'paid' => 2, 'cancelled' => 2, 'expired' => 1])
            ->assertJsonPath('data.tickets.sold', 3)->assertJsonPath('data.tickets.active', 1)
            ->assertJsonPath('data.tickets.checked_in', 1)->assertJsonPath('data.tickets.refund_required', 1)
            ->assertJsonPath('data.tickets.available', 14)->assertJsonPath('data.buyers', 2)
            ->assertJsonPath('data.money', ['currency' => 'UZS', 'gross_received' => '4.86',
                'refund_required' => '2.76', 'excluding_required_refunds' => '2.10'])
            ->assertJsonPath('data.ticket_types.0.price', '999.99')
            ->assertJsonPath('data.ticket_types.0.sold', 3)
            ->assertJsonPath('data.ticket_types.1.id', $unusedType->id)
            ->assertJsonPath('data.ticket_types.1.sold', 0);
    }

    public function testEmptyDraftReportContainsZeros(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['status' => 'draft']);
        Sanctum::actingAs($event->organizer);
        $this->getJson("/api/events/{$event->id}/sales")->assertOk()
            ->assertJsonPath('data.event_status', 'draft')->assertJsonPath('data.buyers', 0)
            ->assertJsonPath('data.tickets.sold', 0)->assertJsonPath('data.money.gross_received', '0.00')
            ->assertJsonPath('data.money.refund_required', '0.00');
        $this->getJson("/api/events/{$event->id}/participants")->assertOk()->assertJsonPath('total', 0);
    }

    public function testReportAfterCancellationRetainsCheckInAndMarksRefunds(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        [$buyer, $event, $type] = $this->createOrderContext();
        $order = $this->purchase($event, $type, $buyer, quantity: 2);
        $ticket = $order->tickets()->first();
        $ticket->status = 'used';
        $ticket->checked_in_at = now();
        $ticket->save();
        app(\App\Services\EventCancellationService::class)->cancel($event, $event->organizer);
        Sanctum::actingAs($event->organizer);
        $this->getJson("/api/events/{$event->id}/sales")->assertOk()
            ->assertJsonPath('data.event_status', 'cancelled')
            ->assertJsonPath('data.tickets.sold', 2)->assertJsonPath('data.tickets.checked_in', 1)
            ->assertJsonPath('data.tickets.active', 0)->assertJsonPath('data.tickets.refund_required', 2)
            ->assertJsonPath('data.money.gross_received', '2.10')
            ->assertJsonPath('data.money.refund_required', '2.10')
            ->assertJsonPath('data.money.excluding_required_refunds', '0.00');
    }

    public function testMoneyPreservesLargeAmountsAndMinorUnits(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $this->purchase($event, $type, $buyer, unitMinor: 999999999999);
        $this->purchase($event, $type, $buyer, unitMinor: 2);
        Sanctum::actingAs($event->organizer);
        $this->getJson("/api/events/{$event->id}/sales")->assertOk()
            ->assertJsonPath('data.money.gross_received', '10000000000.01');
    }
}
