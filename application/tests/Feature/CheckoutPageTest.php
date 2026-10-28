<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\PaymentSignature;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class CheckoutPageTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->configurePaymentGateway();
        Mail::fake();
        Http::fake(['payments.test/*' => Http::response(['data' => ['url' => 'http://payments.test/process-payment/10']], 201)]);
    }

    private function selection(int $type, int $quantity = 2): array
    {
        return ['checkout_reference' => (string) Str::uuid(), 'items' => [['ticket_type_id' => $type, 'quantity' => $quantity]]];
    }

    public function testGuestCanSeeTicketOptionsAndLoginReturnsToEvent(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext('1.05');
        $this->get('/events/'.$event->id)->assertOk()->assertViewIs('checkout.event')
            ->assertSee($type->name)->assertSee('1,05 UZS')->assertSee('Войти и выбрать билеты');
        $this->postJson('/login', ['email' => $buyer->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('redirect', route('events.show', $event));
    }

    public function testEventAndTicketTextIsEscaped(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $event->update(['title' => '<script>bad()</script>', 'description' => '<img src=x onerror=bad()>']);
        $type->update(['name' => '<svg onload=bad()>']);
        $this->actingAs($buyer)->get('/events/'.$event->id)->assertOk()
            ->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)->assertSee('&lt;svg onload=bad()&gt;', false)
            ->assertDontSee('<script>bad()</script>', false)->assertDontSee('<img src=x onerror=bad()>', false);
    }

    public function testDraftIsHiddenAndStartedPublishedEventShowsClosedSales(): void
    {
        [, $event] = $this->createOrderContext();
        $event->update(['status' => 'draft']);
        $this->get('/events/'.$event->id)->assertNotFound();
        $event->update(['status' => 'published', 'starts_at' => now()->subMinute()]);
        $this->get('/events/'.$event->id)->assertOk()->assertSee('Продажа закрыта');
    }

    public function testSessionCheckoutReturnsOrderPageAndServerCalculatedPrice(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext('1.05');
        $selection = $this->selection($type->id) + ['total_amount' => '0.01', 'user_id' => 999, 'status' => 'paid'];
        $this->actingAs($buyer, 'web')->postJson('/events/'.$event->id.'/checkout', $selection)->assertOk();
        $order = Order::sole();
        $this->assertSame($buyer->id, $order->user_id);
        $this->assertSame('2.10', $order->total_amount);
        $this->assertSame('pending', $order->status);
        $this->assertSame(8, $type->fresh()->quantity);
        $this->assertSame(0, $buyer->tokens()->count());
        $this->assertSame($order->id, session('checkout_order_id'));
        $this->get('/orders/'.$order->id)->assertOk()->assertViewIs('checkout.order')
            ->assertSee('2,10 UZS')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/orders/'.$order->id.'/status')->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.payment.status', 'pending');
    }

    public function testRetryWithSameReferenceDoesNotReserveTwiceOrCreateAnotherPayment(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext('1.05');
        $selection = $this->selection($type->id);
        $this->actingAs($buyer)->postJson('/events/'.$event->id.'/checkout', $selection)->assertOk();
        $type->update(['price' => '9.99']);
        $this->postJson('/events/'.$event->id.'/checkout', $selection)->assertOk()
            ->assertJsonPath('redirect', route('checkout.order', Order::sole()));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('2.10', Order::sole()->total_amount);
        $this->assertSame(8, $type->fresh()->quantity);
        Http::assertSentCount(1);
    }

    public function testSameReferenceWithChangedItemsIsRejected(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext('1.05');
        $selection = $this->selection($type->id);
        $this->actingAs($buyer)->postJson('/events/'.$event->id.'/checkout', $selection)->assertOk();
        $selection['items'][0]['quantity'] = 3;
        $this->postJson('/events/'.$event->id.'/checkout', $selection)->assertConflict();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(8, $type->fresh()->quantity);
    }

    public function testReorderedSelectionWithSameReferenceStillReturnsSameOrder(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext('1.05');
        $other = $event->ticketTypes()->create(['name' => 'VIP', 'price' => '2.10', 'quantity' => 2]);
        $selection = $this->selection($type->id);
        $selection['items'][] = ['ticket_type_id' => $other->id, 'quantity' => 1];
        $this->actingAs($buyer)->postJson('/events/'.$event->id.'/checkout', $selection)->assertOk();
        $selection['items'] = array_reverse($selection['items']);
        $this->postJson('/events/'.$event->id.'/checkout', $selection)->assertOk();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(1, $other->fresh()->quantity);
        Http::assertSentCount(1);
    }

    public function testGuestAndBlockedUserCannotCreateCheckout(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $this->postJson('/events/'.$event->id.'/checkout', $this->selection($type->id))->assertUnauthorized();
        $buyer->forceFill(['is_blocked' => true])->save();
        $this->actingAs($buyer)->postJson('/events/'.$event->id.'/checkout', $this->selection($type->id))->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function testAnotherUserCannotReadCancelOrReuseCheckout(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $selection = $this->selection($type->id);
        $this->actingAs($buyer)->postJson('/events/'.$event->id.'/checkout', $selection)->assertOk();
        $order = Order::sole();
        $this->actingAs(User::factory()->create());
        $this->get('/orders/'.$order->id)->assertForbidden();
        $this->getJson('/orders/'.$order->id.'/status')->assertForbidden();
        $this->postJson('/orders/'.$order->id.'/cancel')->assertForbidden();
        $this->postJson('/events/'.$event->id.'/checkout', $selection)->assertForbidden();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseCount('orders', 1);
    }

    public function testGatewayQueryParametersCannotConfirmOrSelectAnotherOrder(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $this->actingAs($buyer)->postJson('/events/'.$event->id.'/checkout', $this->selection($type->id))->assertOk();
        $order = Order::sole();
        $this->get('/payment/result?status=paid&order_id=999&success=1')->assertRedirect(route('checkout.order', $order));
        $this->getJson('/orders/'.$order->id.'/status')->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseCount('tickets', 0);
    }

    public function testWebhookConfirmsCheckoutAndIssuesTicketsOnce(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext('1.05');
        $this->actingAs($buyer)->postJson('/events/'.$event->id.'/checkout', $this->selection($type->id))->assertOk();
        $payment = Order::sole()->payment;
        $data = ['order_id' => $payment->reference, 'amount' => 210, 'status' => 1];
        $headers = ['X-Signature' => app(PaymentSignature::class)->sign($data, 'test-secret')];
        $this->postJson('/api/payments/webhook', $data, $headers)->assertOk();
        $this->postJson('/api/payments/webhook', $data, $headers)->assertOk();
        $this->getJson('/orders/'.Order::sole()->id.'/status')->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonCount(2, 'data.tickets');
    }

    public function testBrowserCancellationReleasesStockOnce(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $this->actingAs($buyer)->postJson('/events/'.$event->id.'/checkout', $this->selection($type->id))->assertOk();
        $order = Order::sole();
        $this->postJson('/orders/'.$order->id.'/cancel')->assertOk();
        $this->postJson('/orders/'.$order->id.'/cancel')->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $type->fresh()->quantity);
    }

    public function testInvalidReferenceAndInsufficientStockCreateNoOrder(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $this->actingAs($buyer)->postJson('/events/'.$event->id.'/checkout', [
            'checkout_reference' => 'invalid', 'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('checkout_reference');
        $this->postJson('/events/'.$event->id.'/checkout', $this->selection($type->id, 11))->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $type->fresh()->quantity);
        Http::assertNothingSent();
    }

    public function testRetryAfterGatewayCrashStillReturnsTheCommittedOrder(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $selection = $this->selection($type->id);
        Http::fake(fn () => throw new RuntimeException('simulated crash'));
        $this->withoutExceptionHandling()->actingAs($buyer);
        try {
            $this->postJson('/events/'.$event->id.'/checkout', $selection);
            $this->fail('Gateway crash must propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('simulated crash', $error->getMessage());
        }
        $this->postJson('/events/'.$event->id.'/checkout', $selection)->assertOk();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(8, $type->fresh()->quantity);
    }

    public function testCheckoutRequiresCsrfToken(): void
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests() { return false; }
        });
        $this->actingAs($buyer)->postJson('/events/'.$event->id.'/checkout', $this->selection($type->id))->assertStatus(419);
        $this->assertDatabaseCount('orders', 0);
    }
}
