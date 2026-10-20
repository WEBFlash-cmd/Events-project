<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\PaymentSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class PaymentIntegrationTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    private mixed $gatewayReply;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePaymentGateway();
        $this->gatewayReply = Http::response(['data' => ['url' => 'http://payments.test/process-payment/100']], 201);
        Http::fake(fn () => $this->gatewayReply);
    }

    public function testCheckoutSendsExactGatewayContractAndReturnsUrl(): void
    {
        [$order, $ticketType] = $this->checkout('0.29');
        $payment = $order->payment;
        $this->assertSame(58, $payment->amount);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('http://payments.test/process-payment/100', $payment->payment_url);
        $this->assertSame(8, $ticketType->fresh()->quantity);
        $this->assertDatabaseCount('tickets', 0);

        Http::assertSent(function ($request) use ($payment, $order) {
            $expectedSignature = hash_hmac('sha256',
                "amount|58|cashbox_id|1|description|Order #{$order->id}|order_id|{$payment->reference}", 'test-secret');

            return $request->url() === 'http://payments.test/api/create-payment'
                && $request['amount'] === 58
                && $request['cashbox_id'] === 1
                && $request['order_id'] === $payment->reference
                && $request->hasHeader('X-Signature', $expectedSignature);
        });
    }

    public function testDuplicateInitializationDoesNotCreateAnotherRemotePayment(): void
    {
        [$order] = $this->checkout();
        app(PaymentService::class)->initialize($order->payment);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function testSuccessfulWebhookIssuesTicketsOnce(): void
    {
        [$order, $ticketType] = $this->checkout();
        $this->webhook($order->payment)->assertOk();
        $this->webhook($order->payment)->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('paid', $order->payment->fresh()->status);
        $this->assertNotNull($order->payment->fresh()->paid_at);
        $this->assertDatabaseCount('tickets', 2);
        $this->assertSame(2, $order->tickets()->distinct()->count('number'));
        $this->assertSame(8, $ticketType->fresh()->quantity);
        foreach ($order->tickets as $ticket) {
            $this->assertSame($order->user_id, $ticket->user_id);
            $this->assertSame($order->event_id, $ticket->event_id);
            $this->assertSame($ticketType->id, $ticket->ticket_type_id);
            $this->assertSame('active', $ticket->status);
        }
        $this->getJson("/api/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonCount(2, 'data.tickets');
        $this->postJson("/api/orders/{$order->id}/cancel")->assertConflict();
    }

    public function testUnsignedAndTamperedWebhooksAreRejected(): void
    {
        [$order, $ticketType] = $this->checkout();
        $data = $this->payload($order->payment);
        $this->postJson('/api/payments/webhook', $data)->assertForbidden();
        $signature = app(PaymentSignature::class)->sign($data, 'test-secret');
        $data['amount']++;
        $this->postJson('/api/payments/webhook', $data, ['X-Signature' => $signature])->assertForbidden();
        $this->postJson('/api/payments/webhook', ['amount' => ['nested']], ['X-Signature' => $signature])->assertForbidden();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(8, $ticketType->fresh()->quantity);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function testEvenCorrectlySignedWrongAmountIsRejected(): void
    {
        [$order] = $this->checkout();
        $this->webhook($order->payment, ['amount' => 1])->assertUnprocessable();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function testWrongCashboxConfigurationCannotSettlePayment(): void
    {
        [$order] = $this->checkout();
        config(['payments.cashbox_id' => 2]);
        $this->webhook($order->payment)->assertUnprocessable();
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function testUnknownReferenceAndUnsupportedStatusAreRejected(): void
    {
        [$order] = $this->checkout();
        $this->webhook($order->payment, ['order_id' => (string) Str::uuid()])->assertNotFound();
        $this->webhook($order->payment, ['status' => 99])->assertUnprocessable();
        $this->assertDatabaseCount('tickets', 0);
    }

    public function testFailedPaymentReleasesStockOnce(): void
    {
        [$order, $ticketType] = $this->checkout();
        $this->webhook($order->payment, ['status' => 2])->assertOk();
        $this->webhook($order->payment, ['status' => 2])->assertOk();
        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        $this->assertSame('failed', $order->payment->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $ticketType->fresh()->quantity);
        $this->assertDatabaseCount('tickets', 0);
    }

    public static function closedOrders(): array
    {
        return ['cancelled' => ['cancelled'], 'already expired' => ['expired'], 'deadline reached' => ['overdue']];
    }

    #[DataProvider('closedOrders')]
    public function testLatePaymentRequiresRefundAndDoesNotIssueTickets(string $reason): void
    {
        [$order, $ticketType] = $this->checkout();
        if ($reason === 'cancelled') {
            $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        } else {
            $this->travelTo($order->expires_at);
            if ($reason === 'expired') {
                $this->artisan('orders:expire')->assertSuccessful();
            }
        }

        $this->webhook($order->payment)->assertOk();
        $this->webhook($order->payment)->assertOk();
        $this->assertSame('refund_required', $order->payment->fresh()->status);
        $this->assertTrue($order->payment->fresh()->refund_required);
        $this->assertSame($reason === 'cancelled' ? 'cancelled' : 'expired', $order->fresh()->status);
        $this->assertSame(10, $ticketType->fresh()->quantity);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function testPaymentForCancelledEventRequiresRefund(): void
    {
        [$order, $ticketType] = $this->checkout();
        $order->event->update(['status' => 'cancelled']);
        $this->webhook($order->payment)->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertTrue($order->payment->fresh()->refund_required);
        $this->assertSame(10, $ticketType->fresh()->quantity);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function testConflictingTerminalNotificationCannotUndoPaidOrder(): void
    {
        [$order, $ticketType] = $this->checkout();
        $this->webhook($order->payment)->assertOk();
        $this->webhook($order->payment, ['status' => 2])->assertConflict();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertDatabaseCount('tickets', 2);
        $this->assertSame(8, $ticketType->fresh()->quantity);
    }

    public function testTimeoutPreservesReferenceAndLaterWebhookCanSettleIt(): void
    {
        $this->gatewayReply = Http::failedConnection();
        [$order] = $this->checkout();
        $this->assertSame('unknown', $order->payment->status);
        $this->assertNull($order->payment->payment_url);
        app(PaymentService::class)->initialize($order->payment);
        $this->assertDatabaseCount('payments', 1);

        $this->webhook($order->payment)->assertOk();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertDatabaseCount('tickets', 2);
    }

    public function testGatewayFailureDoesNotLeaveAnUntrackedRemotePayment(): void
    {
        $this->gatewayReply = Http::response(['message' => 'Internal error'], 500);
        [$order, $ticketType] = $this->checkout();
        $this->assertSame('unknown', $order->payment->status);
        $this->assertSame(8, $ticketType->fresh()->quantity);
        $this->travelTo($order->expires_at);
        $this->artisan('orders:expire')->assertSuccessful();
        $this->assertSame(10, $ticketType->fresh()->quantity);
    }

    public function testGatewayRejectionAndMalformedSuccessDoNotReturnFakeUrl(): void
    {
        $this->gatewayReply = Http::response(['message' => 'Invalid signature'], 400);
        [$order] = $this->checkout();
        $this->assertSame('creation_failed', $order->payment->status);
        $this->assertNull($order->payment->payment_url);

        $this->gatewayReply = Http::response(['data' => ['url' => 'javascript:alert(1)']], 201);
        [$anotherOrder] = $this->checkout();
        $this->assertSame('unknown', $anotherOrder->payment->status);
        $this->assertNull($anotherOrder->payment->payment_url);
    }

    public function testWebhookBeforeGatewayResponseIsNotOverwritten(): void
    {
        $this->gatewayReply = function ($request) {
            app(PaymentService::class)->processWebhook([
                'order_id' => $request['order_id'], 'amount' => $request['amount'], 'status' => 1,
            ]);

            return Http::response(['data' => ['url' => 'http://payments.test/process-payment/100']], 201);
        };
        [$order] = $this->checkout();
        $this->assertSame('paid', $order->status);
        $this->assertSame('paid', $order->payment->status);
        $this->assertDatabaseCount('tickets', 2);
    }

    public function testMissingConfigurationRollsBackOrderAndStock(): void
    {
        config(['payments.secret' => '']);
        [$participant, $event, $ticketType] = $this->createOrderContext();
        Sanctum::actingAs($participant);
        $this->postJson("/api/events/{$event->id}/orders", [
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ])->assertStatus(503);
        Http::assertNothingSent();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(10, $ticketType->fresh()->quantity);
    }

    public function testZeroTotalIsRejectedWithoutReservingTickets(): void
    {
        [$participant, $event, $ticketType] = $this->createOrderContext('0.00');
        Sanctum::actingAs($participant);
        $this->postJson("/api/events/{$event->id}/orders", [
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ])->assertUnprocessable();
        Http::assertNothingSent();
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $ticketType->fresh()->quantity);
    }

    public function testAnotherUserCannotReadOrderPaymentOrTickets(): void
    {
        [$order] = $this->checkout();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::PARTICIPANT]));
        $this->getJson("/api/orders/{$order->id}")->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $this->getJson("/api/orders/{$order->id}")->assertOk();
    }

    private function checkout(string $price = '100000.00'): array
    {
        [$participant, $event, $ticketType] = $this->createOrderContext($price);
        Sanctum::actingAs($participant);
        $response = $this->postJson("/api/events/{$event->id}/orders", [
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ])->assertCreated();

        return [Order::findOrFail($response->json('data.id')), $ticketType];
    }

    private function payload(Payment $payment): array
    {
        return ['order_id' => $payment->reference, 'amount' => $payment->amount, 'status' => 1];
    }

    private function webhook(Payment $payment, array $overrides = [])
    {
        $payload = array_replace($this->payload($payment), $overrides);

        return $this->postJson('/api/payments/webhook', $payload, [
            'X-Signature' => app(PaymentSignature::class)->sign($payload, 'test-secret'),
        ]);
    }
}
