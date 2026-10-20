<?php

namespace Tests\Feature;

use App\Events\OrderPaid;
use App\Listeners\SendPurchaseConfirmation;
use App\Mail\PurchaseConfirmation;
use App\Models\Order;
use App\Services\PaymentService;
use App\Services\PaymentSignature;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class PurchaseConfirmationTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePaymentGateway();
        Http::fake(['payments.test/*' => Http::response([
            'data' => ['url' => 'http://payments.test/process-payment/100'],
        ], 201)]);
        Queue::fake();
        Mail::fake();
    }

    public function testOrderPaidHasPurchaseConfirmationListener(): void
    {
        Event::fake();
        Event::assertListening(OrderPaid::class, SendPurchaseConfirmation::class);
    }

    public function testPaymentQueuesOneConfirmationDespiteRepeatedWebhook(): void
    {
        $order = $this->checkout();
        Queue::assertNothingPushed();

        $this->webhook($order)->assertOk();
        $this->webhook($order)->assertOk();

        Queue::assertPushed(CallQueuedListener::class, function ($job, $queue) use ($order) {
            return $job->class === SendPurchaseConfirmation::class
                && $job->data[0]->order->id === $order->id
                && $queue === 'notifications';
        });
        Queue::assertPushed(CallQueuedListener::class, 1);
        Mail::assertNothingSent();
        $this->assertNull($order->fresh()->purchase_confirmation_sent_at);
    }

    public function testWorkerSendsOrderDetailsAndAllTicketNumbersOnlyOnce(): void
    {
        $order = $this->checkout();
        $this->webhook($order)->assertOk();
        $listener = app(SendPurchaseConfirmation::class);
        $listener->handle(new OrderPaid($order));
        $listener->handle(new OrderPaid($order));

        Mail::assertSent(PurchaseConfirmation::class, function ($mail) use ($order) {
            $mail->assertTo($order->user->email);
            $mail->assertHasSubject("Покупка подтверждена — заказ №{$order->id}");
            $mail->assertSeeInHtml($order->event->title);
            $mail->assertSeeInHtml($order->event->venue->name);
            $mail->assertSeeInHtml($order->event->starts_at->format('d.m.Y H:i'));
            $mail->assertSeeInHtml('200000.00 UZS');
            foreach ($order->tickets as $ticket) {
                $mail->assertSeeInHtml($ticket->number);
                $mail->assertSeeInHtml($ticket->ticketType->name);
            }

            return true;
        });
        Mail::assertSent(PurchaseConfirmation::class, 1);
        $this->assertNotNull($order->fresh()->purchase_confirmation_sent_at);
    }

    public function testRolledBackPaymentDoesNotQueueMail(): void
    {
        $order = $this->checkout();
        try {
            DB::transaction(function () use ($order) {
                app(PaymentService::class)->processWebhook($this->payload($order));
                Queue::assertNothingPushed();
                throw new RuntimeException('Rollback payment');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback payment', $exception->getMessage());
        }

        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function testFailedAndLatePaymentsDoNotQueuePurchaseConfirmation(): void
    {
        $failed = $this->checkout();
        $this->webhook($failed, 2)->assertOk();
        $late = $this->checkout();
        $this->travelTo($late->expires_at);
        $this->webhook($late)->assertOk();

        $this->assertTrue($late->payment->fresh()->refund_required);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public static function unpaidStatuses(): array
    {
        return ['pending' => ['pending'], 'cancelled' => ['cancelled'], 'expired' => ['expired']];
    }

    #[DataProvider('unpaidStatuses')]
    public function testWorkerDoesNotSendForUnpaidOrder(string $status): void
    {
        $order = $this->checkout();
        $order->update(['status' => $status]);
        app(SendPurchaseConfirmation::class)->handle(new OrderPaid($order));

        Mail::assertNothingSent();
        $this->assertNull($order->fresh()->purchase_confirmation_sent_at);
    }

    public function testMailFailureCanBeRetriedWithoutLosingPaidOrder(): void
    {
        $order = $this->checkout();
        $this->webhook($order)->assertOk();
        $mailFake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->with($order->user->email)
            ->andThrow(new RuntimeException('SMTP unavailable'));

        try {
            app(SendPurchaseConfirmation::class)->handle(new OrderPaid($order));
            $this->fail('The queue worker must receive the mail error for retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame('SMTP unavailable', $exception->getMessage());
        }

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(2, $order->tickets()->count());
        $this->assertNull($order->fresh()->purchase_confirmation_sent_at);
        Mail::swap($mailFake);
        app(SendPurchaseConfirmation::class)->handle(new OrderPaid($order));
        Mail::assertSent(PurchaseConfirmation::class, 1);
        $this->assertNotNull($order->fresh()->purchase_confirmation_sent_at);
    }

    private function checkout(): Order
    {
        [$participant, $event, $ticketType] = $this->createOrderContext();
        Sanctum::actingAs($participant);
        $response = $this->postJson("/api/events/{$event->id}/orders", [
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ])->assertCreated();

        return Order::findOrFail($response->json('data.id'));
    }

    private function payload(Order $order, int $status = 1): array
    {
        return ['order_id' => $order->payment->reference, 'amount' => $order->payment->amount, 'status' => $status];
    }

    private function webhook(Order $order, int $status = 1)
    {
        $payload = $this->payload($order, $status);

        return $this->postJson('/api/payments/webhook', $payload, [
            'X-Signature' => app(PaymentSignature::class)->sign($payload, 'test-secret'),
        ]);
    }
}
