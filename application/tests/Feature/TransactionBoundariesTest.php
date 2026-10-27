<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\WaitlistEntry;
use App\Models\User;
use App\Services\EventCancellationService;
use App\Services\EventLifecycleService;
use App\Services\OrderReservationService;
use App\Services\PaymentService;
use App\Services\TicketCancellationService;
use App\Services\TicketTypeService;
use App\Services\WaitlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class TransactionBoundariesTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    private function offeredPair(): array
    {
        $this->configurePaymentGateway();
        config(['queue.default' => 'database']);
        [$buyer, $event, $type] = $this->createOrderContext('1.00');
        $type->update(['quantity' => 0]);
        $waitlist = app(WaitlistService::class);
        $first = $waitlist->join($event, $type, $buyer);
        $second = $waitlist->join($event, $type, User::factory()->create());
        $type->update(['quantity' => 1]);
        $waitlist->process($event);
        DB::table('jobs')->delete();

        return [$event, $type, $first->fresh(), $second->fresh()];
    }

    private function failEntryUpdate(WaitlistEntry $target, string $status): void
    {
        Event::listen('eloquent.updating: '.WaitlistEntry::class, function (WaitlistEntry $entry) use ($target, $status) {
            if ($entry->id === $target->id && $entry->status === $status) {
                throw new RuntimeException('injected waitlist failure');
            }
        });
    }

    private function assertFailure(callable $action, string $message = 'injected waitlist failure'): void
    {
        try {
            $action();
            $this->fail('The injected failure must propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame($message, $error->getMessage());
        }
        $this->assertDatabaseCount('jobs', 0);
    }

    private function pay(Order $order): void
    {
        $payments = app(PaymentService::class);
        $payment = $order->payment()->first() ?? $payments->prepare($order);
        $payments->processWebhook(['order_id' => $payment->reference, 'amount' => $payment->amount, 'status' => 1]);
    }

    public function testLeavingAndPromotionRollBackTogether(): void
    {
        [$event, $type, $first, $second] = $this->offeredPair();
        $this->failEntryUpdate($second, 'offered');
        $this->assertFailure(fn () => app(WaitlistService::class)->leave($event, $type, $first->user));
        $this->assertSame('offered', $first->fresh()->status);
        $this->assertSame('pending', $first->order->fresh()->status);
        $this->assertSame('waiting', $second->fresh()->status);
        $this->assertSame(0, $type->fresh()->quantity);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function testReservationCancellationAndPromotionRollBackTogether(): void
    {
        [, $type, $first, $second] = $this->offeredPair();
        $this->failEntryUpdate($second, 'offered');
        $this->assertFailure(fn () => app(OrderReservationService::class)->cancel($first->order));
        $this->assertSame('pending', $first->order->fresh()->status);
        $this->assertSame('offered', $first->fresh()->status);
        $this->assertSame('waiting', $second->fresh()->status);
        $this->assertSame(0, $type->fresh()->quantity);
        $this->assertDatabaseCount('orders', 1);
    }

    public function testPaidWebhookAndWaitlistStatusRollBackTogether(): void
    {
        [, , $first] = $this->offeredPair();
        $order = $first->order;
        $payment = app(PaymentService::class)->prepare($order);
        $this->failEntryUpdate($first, 'purchased');
        $this->assertFailure(fn () => $this->pay($order));
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('created', $payment->fresh()->status);
        $this->assertSame('offered', $first->fresh()->status);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function testExpiryAndNextOfferRollBackTogether(): void
    {
        [, $type, $first, $second] = $this->offeredPair();
        $this->travelTo($first->order->expires_at);
        $this->failEntryUpdate($second, 'offered');
        $this->assertFailure(fn () => app(OrderReservationService::class)->expire($first->order));
        $this->assertSame('pending', $first->order->fresh()->status);
        $this->assertSame('offered', $first->fresh()->status);
        $this->assertSame('waiting', $second->fresh()->status);
        $this->assertSame(0, $type->fresh()->quantity);
        $this->assertDatabaseCount('orders', 1);
    }

    public function testStockIncreaseAndNextOfferRollBackTogether(): void
    {
        [$event, $type, , $second] = $this->offeredPair();
        $this->failEntryUpdate($second, 'offered');
        $this->assertFailure(fn () => app(TicketTypeService::class)->updateTicketType($event, $type, ['quantity' => 1]));
        $this->assertSame('waiting', $second->fresh()->status);
        $this->assertSame(0, $type->fresh()->quantity);
        $this->assertDatabaseCount('orders', 1);
    }

    public function testTicketCancellationRefundAndPromotionRollBackTogether(): void
    {
        [$event, $type, $first, $second] = $this->offeredPair();
        $event->update(['allows_ticket_cancellation' => true]);
        $this->pay($first->order);
        DB::table('jobs')->delete();
        $ticket = $first->order->tickets()->firstOrFail();
        $this->failEntryUpdate($second, 'offered');
        $this->assertFailure(fn () => app(TicketCancellationService::class)->cancel($ticket, $first->user));
        $this->assertSame('active', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->cancelled_at);
        $this->assertSame('paid', $first->order->payment->fresh()->status);
        $this->assertFalse($first->order->payment->fresh()->refund_required);
        $this->assertSame(0, $first->order->payment->fresh()->refund_amount);
        $this->assertSame('waiting', $second->fresh()->status);
        $this->assertSame(0, $type->fresh()->quantity);
        $this->assertDatabaseCount('orders', 1);
    }

    public function testEventCancellationAndWaitlistClosureRollBackTogether(): void
    {
        [$event, $type, $first, $second] = $this->offeredPair();
        $this->failEntryUpdate($second, 'cancelled');
        $this->assertFailure(fn () => app(EventCancellationService::class)->cancel($event, $event->organizer));
        $this->assertSame(EventStatus::PUBLISHED, $event->fresh()->status);
        $this->assertSame('pending', $first->order->fresh()->status);
        $this->assertSame('offered', $first->fresh()->status);
        $this->assertSame('waiting', $second->fresh()->status);
        $this->assertSame(0, $type->fresh()->quantity);
    }

    public function testEventStartAndClosingOffersRollBackTogether(): void
    {
        [$event, $type, $first, $second] = $this->offeredPair();
        $event->update(['starts_at' => now()]);
        $this->failEntryUpdate($second, 'cancelled');
        $this->assertFailure(fn () => app(EventLifecycleService::class)->synchronize($event));
        $this->assertNull($event->fresh()->started_at);
        $this->assertSame('pending', $first->order->fresh()->status);
        $this->assertSame('offered', $first->fresh()->status);
        $this->assertSame(0, $type->fresh()->quantity);
    }

    public function testTicketIssueFailureRollsBackPaidStatusesAndPreviouslyIssuedTickets(): void
    {
        $this->configurePaymentGateway();
        config(['queue.default' => 'database']);
        [$buyer, $event, $type] = $this->createOrderContext('1.00');
        $order = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id,
            'status' => 'pending', 'total_amount' => '2.00', 'expires_at' => now()->addMinutes(15)]);
        $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => 2, 'unit_price' => '1.00']);
        $payment = app(PaymentService::class)->prepare($order);
        Event::listen('eloquent.creating: '.Ticket::class, function (Ticket $ticket) {
            if ($ticket->sequence === 2) {
                throw new RuntimeException('ticket issue failed');
            }
        });
        $this->assertFailure(fn () => $this->pay($order), 'ticket issue failed');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('created', $payment->fresh()->status);
        $this->assertDatabaseCount('tickets', 0);
    }
}
