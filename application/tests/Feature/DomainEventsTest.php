<?php

namespace Tests\Feature;

use App\Events\EventCancelled;
use App\Events\EventPublished;
use App\Events\OrderPaid;
use App\Events\TicketPurchased;
use App\Models\Order;
use App\Services\EventCancellationService;
use App\Services\EventService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class DomainEventsTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    private function order(): Order
    {
        $this->configurePaymentGateway();
        [$buyer, $event, $type] = $this->createOrderContext('1.05');
        $order = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id,
            'status' => 'pending', 'total_amount' => '2.10', 'expires_at' => now()->addMinutes(15)]);
        $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => 2, 'unit_price' => '1.05']);
        $type->decrement('quantity', 2);
        $order->payment()->create(['reference' => (string) Str::uuid(), 'cashbox_id' => 1,
            'amount' => 210, 'status' => 'pending']);
        return $order;
    }

    private function pay(Order $order, int $status = 1): void
    {
        app(PaymentService::class)->processWebhook(['order_id' => $order->payment->reference, 'amount' => 210, 'status' => $status]);
    }

    public function testEachTicketDispatchesPurchaseOnceDespiteRepeatedWebhook(): void
    {
        Event::fake([TicketPurchased::class, OrderPaid::class]);
        $order = $this->order();
        $this->pay($order);
        $this->pay($order);
        Event::assertDispatchedTimes(TicketPurchased::class, 2);
        Event::assertDispatchedTimes(OrderPaid::class, 1);
        foreach ($order->tickets()->get() as $ticket) {
            Event::assertDispatched(TicketPurchased::class, fn ($signal) => $signal->ticket->id === $ticket->id);
        }
    }

    public function testFailedAndLatePaymentsDoNotDispatchPurchases(): void
    {
        Event::fake([TicketPurchased::class, OrderPaid::class]);
        $failed = $this->order();
        $this->pay($failed, 2);
        $late = $this->order();
        $this->travelTo($late->expires_at);
        $this->pay($late);
        Event::assertNotDispatched(TicketPurchased::class);
        Event::assertNotDispatched(OrderPaid::class);
    }

    public function testRolledBackPaymentDoesNotDispatchPurchases(): void
    {
        Event::fake([TicketPurchased::class, OrderPaid::class]);
        $order = $this->order();
        try {
            DB::transaction(function () use ($order) {
                $this->pay($order);
                Event::assertNotDispatched(TicketPurchased::class);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        Event::assertNotDispatched(TicketPurchased::class);
        Event::assertNotDispatched(OrderPaid::class);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $order->tickets()->count());
    }

    public function testEventCancellationDispatchesOnceEvenWithNoOrders(): void
    {
        Event::fake([EventCancelled::class]);
        [, $event] = $this->createOrderContext();
        $service = app(EventCancellationService::class);
        $service->cancel($event, $event->organizer);
        $service->cancel($event, $event->organizer);
        Event::assertDispatchedTimes(EventCancelled::class, 1);
        Event::assertDispatched(EventCancelled::class, fn ($signal) => $signal->event->id === $event->id);
    }

    public function testRolledBackCancellationDoesNotDispatchEvent(): void
    {
        Event::fake([EventCancelled::class]);
        [, $event] = $this->createOrderContext();
        try {
            DB::transaction(function () use ($event) {
                app(EventCancellationService::class)->cancel($event, $event->organizer);
                Event::assertNotDispatched(EventCancelled::class);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        Event::assertNotDispatched(EventCancelled::class);
        $this->assertSame('published', $event->fresh()->status->value);
    }

    public function testRolledBackPublicationDoesNotDispatchEvent(): void
    {
        Event::fake([EventPublished::class]);
        [, $event] = $this->createOrderContext();
        $event->update(['status' => 'draft']);
        try {
            DB::transaction(function () use ($event) {
                app(EventService::class)->publishEvent($event);
                Event::assertNotDispatched(EventPublished::class);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        Event::assertNotDispatched(EventPublished::class);
        $this->assertSame('draft', $event->fresh()->status->value);
    }
}
