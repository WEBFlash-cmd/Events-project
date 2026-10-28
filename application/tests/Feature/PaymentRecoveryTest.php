<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class PaymentRecoveryTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    private function abandoned(string $status = 'creating', int $age = 180): array
    {
        $this->configurePaymentGateway();
        Queue::fake();
        [$buyer, $event, $type] = $this->createOrderContext('1.05');
        $order = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id,
            'status' => 'pending', 'total_amount' => '1.05', 'expires_at' => now()->addMinutes(15)]);
        $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => 1, 'unit_price' => '1.05']);
        $type->decrement('quantity');
        $payment = app(PaymentService::class)->prepare($order);
        $payment->forceFill(['status' => $status, 'updated_at' => now()->subSeconds($age)])->save();

        return [$payment, $order, $type];
    }

    public static function unfinishedStatuses(): array
    {
        return [['created'], ['creating']];
    }

    #[DataProvider('unfinishedStatuses')]
    public function testRecoveryReusesReferenceAndDoesNotRepeatCompletedInitialization(string $status): void
    {
        [$payment] = $this->abandoned($status);
        Http::fake(['payments.test/*' => Http::response(['data' => ['url' => 'http://payments.test/process-payment/10']], 201)]);
        $this->artisan('payments:recover')->assertSuccessful();
        $this->artisan('payments:recover')->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['order_id'] === $payment->reference
            && $request['cashbox_id'] === $payment->cashbox_id && $request['amount'] === 105);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('http://payments.test/process-payment/10', $payment->fresh()->payment_url);
        $this->assertDatabaseCount('payments', 1);
    }

    public function testLiveAttemptCannotBeReclaimedByAnotherRecoveryCall(): void
    {
        [$payment] = $this->abandoned(age: 30);
        Http::fake();
        $this->artisan('payments:recover')->assertSuccessful();
        app(PaymentService::class)->initialize($payment, recoverStalled: true);
        Http::assertNothingSent();
        $this->assertSame('creating', $payment->fresh()->status);
    }

    public function testRecoveryDelayCannotBeShorterThanHttpTimeout(): void
    {
        [$payment] = $this->abandoned(age: 180);
        config(['payments.timeout' => 300, 'payments.recovery_after_seconds' => 1]);
        Http::fake();
        $this->artisan('payments:recover')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame('creating', $payment->fresh()->status);
    }

    public function testRepeatedReferenceRejectionStaysUnknownAndLateWebhookSettlesOnce(): void
    {
        [$payment, $order] = $this->abandoned();
        Http::fake(['payments.test/*' => Http::response(['errors' => ['order_id' => ['Already exists.']]], 422)]);
        $this->artisan('payments:recover')->assertSuccessful();
        $this->assertSame('unknown', $payment->fresh()->status);
        $this->artisan('payments:recover')->assertSuccessful();
        Http::assertSentCount(1);
        $data = ['order_id' => $payment->reference, 'amount' => 105, 'status' => 1];
        app(PaymentService::class)->processWebhook($data);
        app(PaymentService::class)->processWebhook($data);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function testExpiredReservationIsReleasedWithoutContactingGateway(): void
    {
        [$payment, $order, $type] = $this->abandoned();
        $order->update(['expires_at' => now()->subSecond()]);
        Http::fake();
        $this->artisan('payments:recover')->assertSuccessful();
        $this->artisan('payments:recover')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame('expired', $order->fresh()->status);
        $this->assertSame(10, $type->fresh()->quantity);
        $this->assertSame('creation_failed', $payment->fresh()->status);
        $this->assertSame('order_closed', $payment->fresh()->error_code);
    }

    public function testClosedEventCannotCreateNewRemotePayment(): void
    {
        [$payment, $order, $type] = $this->abandoned();
        $order->event->update(['status' => 'cancelled']);
        Http::fake();
        $this->artisan('payments:recover')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $type->fresh()->quantity);
        $this->assertSame('creation_failed', $payment->fresh()->status);
    }

    public function testWebhookDuringRecoveryCannotBeOverwrittenByHttpResponse(): void
    {
        [$payment, $order] = $this->abandoned();
        Http::fake(function ($request) use ($payment) {
            // A second recovery sees the renewed timestamp and must not issue another HTTP call.
            app(PaymentService::class)->initialize($payment, recoverStalled: true);
            app(PaymentService::class)->processWebhook([
                'order_id' => $request['order_id'], 'amount' => $request['amount'], 'status' => 1,
            ]);
            return Http::response(['data' => ['url' => 'http://payments.test/process-payment/10']], 201);
        });
        $this->artisan('payments:recover')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertDatabaseCount('tickets', 1);
    }

    public function testTimeoutDoesNotLoseReferenceAndNextRunDoesNotRepeatUnknownPayment(): void
    {
        [$payment] = $this->abandoned();
        Http::fake(['payments.test/*' => Http::failedConnection()]);
        $this->artisan('payments:recover')->assertSuccessful();
        $this->assertSame('unknown', $payment->fresh()->status);
        $this->assertSame('gateway_unavailable', $payment->fresh()->error_code);
        $this->assertNotNull($payment->fresh()->reference);
        $this->artisan('payments:recover')->assertSuccessful();
        $this->assertDatabaseCount('payments', 1);
    }
}
