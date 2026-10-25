<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\EventCancellationService;
use App\Services\PaymentService;
use App\Services\TicketCancellationService;
use App\Services\TicketTypeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class TicketCancellationTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    private User $buyer;
    private Event $event;
    private Order $order;
    private TicketType $type;
    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        [$this->buyer, $this->event, $this->type] = $this->createOrderContext('1.05');
        $this->event->update(['allows_ticket_cancellation' => true]);
        $this->order = Order::create(['user_id' => $this->buyer->id, 'event_id' => $this->event->id,
            'status' => 'paid', 'total_amount' => '2.10']);
        $item = $this->order->items()->create(['ticket_type_id' => $this->type->id, 'quantity' => 2, 'unit_price' => '1.05']);
        $this->type->decrement('quantity', 2);
        $this->order->payment()->create(['reference' => (string) Str::uuid(), 'cashbox_id' => 1,
            'amount' => 210, 'status' => 'paid', 'paid_at' => now()]);
        foreach ([1, 2] as $sequence) {
            $this->order->tickets()->create(['number' => (string) Str::uuid(), 'user_id' => $this->buyer->id,
                'event_id' => $this->event->id, 'ticket_type_id' => $this->type->id,
                'order_item_id' => $item->id, 'sequence' => $sequence, 'status' => 'active']);
        }
        $this->ticket = $this->order->tickets()->first();
    }

    private function url(): string
    {
        return "/api/tickets/{$this->ticket->id}/cancel";
    }

    public function testOwnerCancelsOneTicketAtHistoricalPriceAndGetsOneSeatBack(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->type->update(['price' => '999.00']);
        Sanctum::actingAs($this->buyer);
        $this->postJson($this->url())->assertOk()->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.refund_required', true);
        $this->assertTrue($this->ticket->fresh()->cancelled_at->equalTo(now()));
        $this->assertSame(9, $this->type->fresh()->quantity);
        $this->assertSame(105, $this->order->payment->fresh()->refund_amount);
        $this->assertSame('refund_required', $this->order->payment->fresh()->status);
        $this->assertSame('paid', $this->order->fresh()->status);
        $this->assertSame(1, $this->order->tickets()->where('status', 'active')->count());
        Sanctum::actingAs($this->event->organizer);
        $this->getJson("/api/events/{$this->event->id}/sales")->assertOk()
            ->assertJsonPath('data.money.gross_received', '2.10')
            ->assertJsonPath('data.money.refund_required', '1.05')
            ->assertJsonPath('data.money.excluding_required_refunds', '1.05');
        $this->postJson("/api/events/{$this->event->id}/tickets/{$this->ticket->id}/check-in")->assertConflict();
    }

    public function testRepeatAndStaleRequestsCannotRefundOrReleaseTwice(): void
    {
        $service = app(TicketCancellationService::class);
        $service->cancel($this->ticket, $this->buyer);
        $originalTime = $this->ticket->fresh()->cancelled_at;
        $this->travel(3)->days();
        $service->cancel($this->ticket, $this->buyer);
        $this->assertSame(9, $this->type->fresh()->quantity);
        $this->assertSame(105, $this->order->payment->fresh()->refund_amount);
        $this->assertTrue($this->ticket->fresh()->cancelled_at->equalTo($originalTime));
    }

    public function testCancellingBothTicketsProducesFullRefundExactlyOnce(): void
    {
        Sanctum::actingAs($this->buyer);
        foreach ($this->order->tickets()->get() as $ticket) {
            $this->postJson("/api/tickets/{$ticket->id}/cancel")->assertOk();
        }
        $this->assertSame(10, $this->type->fresh()->quantity);
        $this->assertSame(210, $this->order->payment->fresh()->refund_amount);
        $this->assertSame(2, $this->order->tickets()->where('status', 'cancelled')->count());
    }

    public function testOnlyOwnerCanCancelIncludingDenialForAdminAndOrganizer(): void
    {
        $this->postJson($this->url())->assertUnauthorized();
        foreach ([$this->event->organizer, User::factory()->create(),
            User::factory()->create(['role' => UserRole::ADMIN])] as $actor) {
            Sanctum::actingAs($actor);
            $this->postJson($this->url())->assertForbidden();
        }
        $this->assertSame('active', $this->ticket->fresh()->status);
        $this->assertSame(8, $this->type->fresh()->quantity);
    }

    public static function blockedConditions(): array
    {
        return [['disabled'], ['start'], ['draft'], ['cancelled'], ['finished'], ['used'], ['checked_in'],
            ['pending'], ['expired'], ['order_cancelled'], ['payment_pending'], ['payment_missing']];
    }

    #[DataProvider('blockedConditions')]
    public function testInvalidCancellationLeavesPaymentAndStockUnchanged(string $condition): void
    {
        match ($condition) {
            'disabled' => $this->event->update(['allows_ticket_cancellation' => false]),
            'start' => $this->event->update(['starts_at' => now()]),
            'draft', 'cancelled', 'finished' => $this->event->update(['status' => $condition]),
            'used' => $this->ticket->update(['status' => 'used']),
            'checked_in' => $this->ticket->forceFill(['checked_in_at' => now()])->save(),
            'pending', 'expired' => $this->order->update(['status' => $condition]),
            'order_cancelled' => $this->order->update(['status' => 'cancelled']),
            'payment_pending' => $this->order->payment->update(['status' => 'pending']),
            'payment_missing' => $this->order->payment()->delete(),
        };
        Sanctum::actingAs($this->buyer);
        $this->postJson($this->url())->assertConflict();
        $this->assertSame(8, $this->type->fresh()->quantity);
        $this->assertNull($this->ticket->fresh()->cancelled_at);
        if ($condition !== 'payment_missing') {
            $this->assertSame(0, $this->order->payment->fresh()->refund_amount);
        }
    }

    public function testEventCancellationAfterPartialCancellationRequiresOnlyFullOrderAmount(): void
    {
        app(TicketCancellationService::class)->cancel($this->ticket, $this->buyer);
        app(EventCancellationService::class)->cancel($this->event, $this->event->organizer);
        $this->assertSame(210, $this->order->payment->fresh()->refund_amount);
        $this->assertSame(9, $this->type->fresh()->quantity);
        $this->assertSame(2, $this->order->tickets()->where('refund_required', true)->count());
    }

    public function testRepeatedSuccessfulWebhookDoesNotResetPartialCancellation(): void
    {
        $this->configurePaymentGateway();
        app(TicketCancellationService::class)->cancel($this->ticket, $this->buyer);
        app(PaymentService::class)->processWebhook(['order_id' => $this->order->payment->reference,
            'amount' => 210, 'status' => 1]);
        $this->assertSame(105, $this->order->payment->fresh()->refund_amount);
        $this->assertSame(2, $this->order->tickets()->count());
        $this->assertSame(9, $this->type->fresh()->quantity);
    }

    public function testReleasedSeatDoesNotGetCountedTwiceAgainstVenueCapacity(): void
    {
        $this->event->venue->update(['capacity' => 10]);
        app(TicketCancellationService::class)->cancel($this->ticket, $this->buyer);
        app(TicketTypeService::class)->updateTicketType($this->event, $this->type, ['quantity' => 9]);
        $this->assertSame(9, $this->type->fresh()->quantity);
    }

    public function testRulesCannotBeChangedAfterTicketSale(): void
    {
        Sanctum::actingAs($this->event->organizer);
        $this->patchJson("/api/events/{$this->event->id}", ['allows_ticket_cancellation' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('allows_ticket_cancellation');
        $this->assertTrue($this->event->fresh()->allows_ticket_cancellation);
    }

    public function testDifferentTicketTypesAccumulateOnlyTheirPurchasedPrices(): void
    {
        $vip = $this->event->ticketTypes()->create(['name' => 'VIP', 'price' => '99.99', 'quantity' => 4]);
        $item = $this->order->items()->create(['ticket_type_id' => $vip->id, 'quantity' => 1, 'unit_price' => '3.37']);
        $this->order->update(['total_amount' => '5.47']);
        $this->order->payment()->update(['amount' => 547]);
        $ticket = $this->order->tickets()->create(['number' => (string) Str::uuid(), 'user_id' => $this->buyer->id,
            'event_id' => $this->event->id, 'ticket_type_id' => $vip->id,
            'order_item_id' => $item->id, 'sequence' => 1, 'status' => 'active']);
        app(TicketCancellationService::class)->cancel($ticket, $this->buyer);
        app(TicketCancellationService::class)->cancel($this->ticket, $this->buyer);
        $this->assertSame(442, $this->order->payment->fresh()->refund_amount);
        $this->assertSame(5, $vip->fresh()->quantity);
        Sanctum::actingAs($this->event->organizer);
        $this->getJson("/api/events/{$this->event->id}/sales")->assertOk()
            ->assertJsonPath('data.money.refund_required', '4.42')
            ->assertJsonPath('data.money.excluding_required_refunds', '1.05');
    }

    public function testZeroPriceTicketDoesNotCreateMonetaryRefund(): void
    {
        $free = $this->event->ticketTypes()->create(['name' => 'Free extra', 'price' => '0.00', 'quantity' => 4]);
        $item = $this->order->items()->create(['ticket_type_id' => $free->id, 'quantity' => 1, 'unit_price' => '0.00']);
        $ticket = $this->order->tickets()->create(['number' => (string) Str::uuid(), 'user_id' => $this->buyer->id,
            'event_id' => $this->event->id, 'ticket_type_id' => $free->id,
            'order_item_id' => $item->id, 'sequence' => 1, 'status' => 'active']);
        $result = app(TicketCancellationService::class)->cancel($ticket, $this->buyer);
        $this->assertSame('cancelled', $result->status);
        $this->assertFalse($result->refund_required);
        $this->assertSame(0, $this->order->payment->fresh()->refund_amount);
        $this->assertSame('paid', $this->order->payment->fresh()->status);
    }

    public function testOrganizerCanEnableCancellationBeforeSaleAndDefaultIsDisabled(): void
    {
        [, $event] = $this->createOrderContext();
        $this->assertFalse($event->fresh()->allows_ticket_cancellation);
        Sanctum::actingAs($event->organizer);
        $this->patchJson("/api/events/{$event->id}", ['allows_ticket_cancellation' => true])
            ->assertOk()->assertJsonPath('data.allows_ticket_cancellation', true);
        $this->patchJson("/api/events/{$event->id}", ['allows_ticket_cancellation' => 'invalid'])->assertUnprocessable();
    }

    public function testClientCannotSetRefundOrCancellationMetadata(): void
    {
        Sanctum::actingAs($this->buyer);
        $this->postJson($this->url(), ['refund_amount' => 999, 'cancelled_at' => now()->toISOString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['refund_amount', 'cancelled_at']);
        $this->assertSame('active', $this->ticket->fresh()->status);
        $this->postJson('/api/tickets/99999999/cancel')->assertNotFound();
    }
}
