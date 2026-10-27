<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Events\EventAvailabilityChanged;
use App\Jobs\SendWaitlistOffer;
use App\Listeners\ProcessWaitlist;
use App\Mail\WaitlistOffer;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\EventCancellationService;
use App\Services\EventLifecycleService;
use App\Services\PaymentSignature;
use App\Services\TicketTypeService;
use App\Services\UserAccessService;
use App\Services\WaitlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class WaitlistTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    private Event $event;
    private TicketType $type;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->configurePaymentGateway();
        [$this->buyer, $this->event, $this->type] = $this->createOrderContext('1.05');
        $this->type->update(['quantity' => 0]);
        Http::fake(['payments.test/*' => Http::response(['data' => ['url' => 'http://payments.test/process-payment/100']], 201)]);
        Queue::fake();
        Mail::fake();
    }

    private function url(): string
    {
        return "/api/events/{$this->event->id}/ticket-types/{$this->type->id}/waitlist";
    }

    private function join(?User $user = null): WaitlistEntry
    {
        $user ??= User::factory()->create();
        $entry = app(WaitlistService::class)->join($this->event, $this->type, $user);
        $this->travel(1)->seconds();
        return $entry;
    }

    private function offer(int $quantity = 1): void
    {
        $this->type->update(['quantity' => $quantity]);
        app(WaitlistService::class)->process($this->event);
    }

    private function webhook(Order $order, int $status = 1)
    {
        $payment = $order->payment()->firstOrFail();
        $payload = ['order_id' => $payment->reference, 'amount' => $payment->amount, 'status' => $status];
        return $this->postJson('/api/payments/webhook', $payload,
            ['X-Signature' => app(PaymentSignature::class)->sign($payload, 'test-secret')]);
    }

    public function testParticipantJoinsReadsAndLeavesOnlyTheirOwnEntry(): void
    {
        Sanctum::actingAs($this->buyer);
        $this->postJson($this->url())->assertCreated()->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.user_id', $this->buyer->id);
        $this->postJson($this->url())->assertOk();
        $this->assertDatabaseCount('waitlist_entries', 1);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.status', 'waiting');
        $this->deleteJson($this->url())->assertNoContent();
        $this->deleteJson($this->url())->assertNoContent();
        $this->assertDatabaseHas('waitlist_entries', ['user_id' => $this->buyer->id, 'status' => 'cancelled']);
        Http::assertNothingSent();
    }

    public function testGuestsCannotUseWaitlist(): void
    {
        $this->postJson($this->url())->assertUnauthorized();
        $this->getJson($this->url())->assertUnauthorized();
        $this->deleteJson($this->url())->assertUnauthorized();
        $this->postJson($this->url().'/checkout')->assertUnauthorized();
    }

    public function testAnotherUserCannotReadCancelOrAcceptAnOffer(): void
    {
        $entry = $this->join();
        $this->offer();
        Sanctum::actingAs($this->buyer);
        $this->getJson($this->url())->assertNotFound();
        $this->deleteJson($this->url())->assertNotFound();
        $this->postJson($this->url().'/checkout')->assertNotFound();
        $this->assertSame('offered', $entry->fresh()->status);
        Http::assertNothingSent();
    }

    public function testWaitlistRejectsForeignTicketTypeAndForgedFields(): void
    {
        Sanctum::actingAs($this->buyer);
        [, $other] = $this->createOrderContext();
        $this->postJson("/api/events/{$other->id}/ticket-types/{$this->type->id}/waitlist")->assertNotFound();
        $this->postJson($this->url(), ['user_id' => 100, 'status' => 'offered', 'order_id' => 100, 'price' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'status', 'order_id', 'price']);
        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    public function testCannotJoinWhenTicketsAreAvailableOrEventIsClosed(): void
    {
        Sanctum::actingAs($this->buyer);
        $this->type->update(['quantity' => 1]);
        $this->postJson($this->url())->assertConflict();
        $this->type->update(['quantity' => 0, 'price' => '0.00']);
        $this->postJson($this->url())->assertConflict();
        $this->type->update(['price' => '1.05']);
        foreach (['draft', 'cancelled', 'finished'] as $status) {
            $this->event->update(['status' => $status]);
            $this->postJson($this->url())->assertConflict();
        }
        $this->event->update(['status' => 'published', 'starts_at' => now()]);
        $this->postJson($this->url())->assertConflict();
        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    public function testFirstParticipantGetsOneReservedSeatAndLaterParticipantsWait(): void
    {
        $first = $this->join();
        $second = $this->join();
        $this->offer();
        app(WaitlistService::class)->process($this->event);
        $first->refresh();
        $this->assertSame('offered', $first->status);
        $this->assertSame('waiting', $second->fresh()->status);
        $this->assertSame(0, $this->type->fresh()->quantity);
        $this->assertSame('1.05', $first->order->total_amount);
        $this->assertSame(1, $first->order->items->first()->quantity);
        $this->assertTrue($first->order->expires_at->equalTo(now()->addMinutes(15)));
        $this->assertNull($first->order->payment);
        $this->assertDatabaseCount('orders', 1);
        Queue::assertPushed(SendWaitlistOffer::class, 1);
        Http::assertNothingSent();
    }

    public function testMultipleReleasedSeatsAreOfferedInQueueOrder(): void
    {
        $first = $this->join();
        $second = $this->join();
        $third = $this->join();
        $this->offer(2);
        $this->assertSame('offered', $first->fresh()->status);
        $this->assertSame('offered', $second->fresh()->status);
        $this->assertSame('waiting', $third->fresh()->status);
        Queue::assertPushed(SendWaitlistOffer::class, 2);
    }

    public function testTicketTypesHaveIndependentQueues(): void
    {
        $first = $this->join();
        $otherType = $this->event->ticketTypes()->create(['name' => 'VIP', 'price' => '2.00', 'quantity' => 0]);
        $vip = app(WaitlistService::class)->join($this->event, $otherType, $this->buyer);
        $otherType->update(['quantity' => 1]);
        app(WaitlistService::class)->process($this->event);
        $this->assertSame('waiting', $first->fresh()->status);
        $this->assertSame('offered', $vip->fresh()->status);
        $this->assertSame($otherType->id, $vip->fresh()->order->items->first()->ticket_type_id);
    }

    public function testOfferDeadlineCannotExceedEventStart(): void
    {
        $entry = $this->join();
        $this->event->update(['starts_at' => now()->addMinutes(5)]);
        $this->offer();
        $this->assertTrue($entry->fresh()->order->expires_at->equalTo($this->event->starts_at));
    }

    public function testPublicCheckoutCannotTakeSeatBeforeWaitlist(): void
    {
        $entry = $this->join();
        $this->type->update(['quantity' => 1]);
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/events/{$this->event->id}/orders", [
            'items' => [['ticket_type_id' => $this->type->id, 'quantity' => 1]],
        ])->assertUnprocessable();
        $this->assertSame('offered', $entry->fresh()->status);
        $this->assertSame($entry->user_id, Order::sole()->user_id);
        Http::assertNothingSent();
    }

    public function testCheckoutUsesOneOrderAndOneGatewayPaymentAndWebhookIssuesOneTicket(): void
    {
        $entry = $this->join();
        $this->offer();
        Sanctum::actingAs($entry->user);
        foreach ([1, 2] as $attempt) {
            $this->postJson($this->url().'/checkout')->assertOk()
                ->assertJsonPath('data.id', $entry->fresh()->order_id)
                ->assertJsonPath('data.payment.payment_url', 'http://payments.test/process-payment/100');
        }
        Http::assertSentCount(1);
        $order = $entry->fresh()->order;
        $this->assertSame(105, $order->payment->amount);
        $this->webhook($order)->assertOk();
        $this->webhook($order)->assertOk();
        $this->assertSame('purchased', $entry->fresh()->status);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $order->tickets()->count());
        $this->assertSame(0, $this->type->fresh()->quantity);
        $this->postJson($this->url().'/checkout')->assertOk()->assertJsonPath('data.status', 'paid');
        Http::assertSentCount(1);
    }

    public function testWaitingParticipantCannotStartPayment(): void
    {
        $entry = $this->join();
        Sanctum::actingAs($entry->user);
        $this->postJson($this->url().'/checkout')->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function testExpiredOfferMovesToNextParticipantAndLatePaymentRequiresRefund(): void
    {
        $first = $this->join();
        $second = $this->join();
        $this->offer();
        Sanctum::actingAs($first->user);
        $this->postJson($this->url().'/checkout')->assertOk();
        $order = $first->fresh()->order;
        $this->travelTo($order->expires_at);
        $this->artisan('waitlist:process')->assertSuccessful();
        $this->assertSame('expired', $first->fresh()->status);
        $this->assertSame('offered', $second->fresh()->status);
        $this->postJson($this->url().'/checkout')->assertConflict();
        $this->webhook($order)->assertOk();
        $this->assertSame('refund_required', $order->payment->fresh()->status);
        $this->assertSame(105, $order->payment->fresh()->refund_amount);
        $this->assertSame(0, $order->tickets()->count());
        $this->assertSame('pending', $second->fresh()->order->status);
        $this->assertSame(0, $this->type->fresh()->quantity);
    }

    public function testLeavingOfferedSeatPromotesNextWithoutDoubleRelease(): void
    {
        $first = $this->join();
        $second = $this->join();
        $this->offer();
        Sanctum::actingAs($first->user);
        $this->deleteJson($this->url())->assertNoContent();
        $this->deleteJson($this->url())->assertNoContent();
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('cancelled', $first->fresh()->order->status);
        $this->assertSame('offered', $second->fresh()->status);
        $this->assertSame(0, $this->type->fresh()->quantity);
        $this->assertDatabaseCount('orders', 2);
    }

    public function testRejoiningGoesToBackOfQueue(): void
    {
        $first = $this->join();
        $second = $this->join();
        app(WaitlistService::class)->leave($this->event, $this->type, $first->user);
        $this->travel(1)->seconds();
        $rejoined = $this->join($first->user);
        $this->assertSame($first->id, $rejoined->id);
        $this->offer();
        $this->assertSame('offered', $second->fresh()->status);
        $this->assertSame('waiting', $first->fresh()->status);
    }

    public function testBlockedParticipantDoesNotKeepOrReceiveReservedSeat(): void
    {
        $first = $this->join();
        $second = $this->join();
        $third = $this->join();
        $this->offer();
        app(UserAccessService::class)->block($first->user);
        app(UserAccessService::class)->block($second->user);
        $this->artisan('waitlist:process')->assertSuccessful();
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('cancelled', $second->fresh()->status);
        $this->assertSame('offered', $third->fresh()->status);
        $this->assertSame(0, $this->type->fresh()->quantity);
    }

    public function testDeclinedPaymentImmediatelyPromotesNextParticipant(): void
    {
        $first = $this->join();
        $second = $this->join();
        $this->offer();
        Sanctum::actingAs($first->user);
        $this->postJson($this->url().'/checkout')->assertOk();
        $this->webhook($first->fresh()->order, 2)->assertOk();
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('offered', $second->fresh()->status);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function testIncreasingStockNotifiesWaitlistAndPreservesVenueCapacity(): void
    {
        $entry = $this->join();
        $this->event->venue->update(['capacity' => 1]);
        Sanctum::actingAs($this->event->organizer);
        $this->patchJson("/api/events/{$this->event->id}/ticket-types/{$this->type->id}", ['quantity' => 1])
            ->assertOk()->assertJsonPath('data.quantity', 0);
        $this->assertSame('offered', $entry->fresh()->status);
        $this->patchJson("/api/events/{$this->event->id}/ticket-types/{$this->type->id}", ['quantity' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
    }

    public function testPriceIsFixedAtOfferAndNotChangedDuringCheckout(): void
    {
        $entry = $this->join();
        $this->type->update(['price' => '2.10']);
        $this->offer();
        $this->type->update(['price' => '3.15']);
        Sanctum::actingAs($entry->user);
        $this->postJson($this->url().'/checkout')->assertOk()->assertJsonPath('data.total_amount', '2.10')
            ->assertJsonPath('data.payment.amount', 210);
    }

    public function testCancellingEventClosesQueueAndDoesNotOfferReleasedSeats(): void
    {
        $first = $this->join();
        $second = $this->join();
        $this->offer();
        app(EventCancellationService::class)->cancel($this->event, $this->event->organizer);
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('cancelled', $second->fresh()->status);
        $this->assertSame('cancelled', $first->fresh()->order->status);
        $this->assertSame(1, $this->type->fresh()->quantity);
        $this->assertDatabaseCount('orders', 1);
        (new SendWaitlistOffer($first->id, $first->fresh()->order_id, $this->event->id))->handle();
        Mail::assertNothingSent();
    }

    public function testEventStartClosesQueue(): void
    {
        $first = $this->join();
        $second = $this->join();
        $this->offer();
        $this->travelTo($this->event->starts_at);
        app(EventLifecycleService::class)->synchronize($this->event);
        $this->assertSame('expired', $first->fresh()->status);
        $this->assertSame('cancelled', $second->fresh()->status);
        $this->assertDatabaseCount('orders', 1);
    }

    public function testQueuedMailContainsDeadlineAndIsSentOnce(): void
    {
        $entry = $this->join();
        $this->offer();
        $entry->refresh();
        $job = new SendWaitlistOffer($entry->id, $entry->order_id, $this->event->id);
        $job->handle();
        $job->handle();
        Mail::assertSent(WaitlistOffer::class, function ($mail) use ($entry) {
            $mail->assertTo($entry->user->email);
            $mail->assertSeeInHtml($this->event->title);
            $mail->assertSeeInHtml('1.05 UZS');
            $mail->assertSeeInHtml($entry->order->expires_at->format('d.m.Y H:i:s'));
            return true;
        });
        Mail::assertSent(WaitlistOffer::class, 1);
        $this->assertNotNull($entry->fresh()->notified_at);
    }

    public function testStaleMailDoesNotNotifyForExpiredOrReplacedOffer(): void
    {
        $entry = $this->join();
        $this->offer();
        $oldOrder = $entry->fresh()->order;
        $job = new SendWaitlistOffer($entry->id, $oldOrder->id, $this->event->id);
        $this->travelTo($oldOrder->expires_at);
        $job->handle();
        app(WaitlistService::class)->process($this->event);
        // A new queue round must never receive the old job's email.
        $this->type->update(['quantity' => 0]);
        $this->join($entry->user);
        $this->offer();
        $job->handle();
        Mail::assertNothingSent();
        $this->assertNotSame($oldOrder->id, $entry->fresh()->order_id);
        $this->assertNull($entry->fresh()->notified_at);
    }

    public function testMailFailureCanBeRetried(): void
    {
        $entry = $this->join();
        $this->offer();
        $entry->refresh();
        $fake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP unavailable'));
        $job = new SendWaitlistOffer($entry->id, $entry->order_id, $this->event->id);
        try {
            $job->handle();
            $this->fail('Mail failure must reach the queue worker.');
        } catch (RuntimeException $error) {
            $this->assertSame('SMTP unavailable', $error->getMessage());
        }
        $this->assertNull($entry->fresh()->notified_at);
        Mail::swap($fake);
        $job->handle();
        Mail::assertSent(WaitlistOffer::class, 1);
    }

    public function testRollbackCreatesNeitherReservationNorQueuedMail(): void
    {
        $entry = $this->join();
        try {
            DB::transaction(function () {
                $this->offer();
                Queue::assertNothingPushed();
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('rollback', $error->getMessage());
        }
        $this->assertSame('waiting', $entry->fresh()->status);
        $this->assertSame(0, $this->type->fresh()->quantity);
        $this->assertDatabaseCount('orders', 0);
        Queue::assertNothingPushed();
    }

    public function testCancellingPaidTicketImmediatelyOffersReleasedSeat(): void
    {
        $this->type->update(['quantity' => 1]);
        $this->event->update(['allows_ticket_cancellation' => true]);
        Sanctum::actingAs($this->buyer);
        $response = $this->postJson("/api/events/{$this->event->id}/orders", [
            'items' => [['ticket_type_id' => $this->type->id, 'quantity' => 1]],
        ])->assertCreated();
        $order = Order::findOrFail($response->json('data.id'));
        $this->webhook($order)->assertOk();
        $entry = $this->join();
        $ticket = $order->tickets()->firstOrFail();
        $this->postJson("/api/tickets/{$ticket->id}/cancel")->assertOk();
        $this->assertSame('offered', $entry->fresh()->status);
        $this->assertSame(0, $this->type->fresh()->quantity);
        $this->assertSame(105, $order->payment->fresh()->refund_amount);
    }

    public function testWaitlistListenersAreDiscovered(): void
    {
        \Illuminate\Support\Facades\Event::fake();
        \Illuminate\Support\Facades\Event::assertListening(EventAvailabilityChanged::class, ProcessWaitlist::class);
    }

    public function testOwnWaitlistIsPaginatedFilterableAndDoesNotExposeOtherUsers(): void
    {
        $this->getJson('/api/waitlist')->assertUnauthorized();
        $this->join();
        foreach (range(1, 16) as $number) {
            $type = $this->event->ticketTypes()->create(['name' => "Type $number", 'price' => '1.05', 'quantity' => 0]);
            app(WaitlistService::class)->join($this->event, $type, $this->buyer);
        }
        Sanctum::actingAs($this->buyer);
        $this->getJson('/api/waitlist')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('total', 16);
        $this->getJson('/api/waitlist?page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/waitlist?status=offered')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/waitlist?user_id=1')->assertUnprocessable();
        $this->getJson('/api/waitlist?per_page=101')->assertUnprocessable();
        $this->getJson('/api/waitlist?status=invalid')->assertUnprocessable();
    }

    public function testRejoiningWithinSameSecondStillGoesToBack(): void
    {
        $now = now();
        $first = $this->join();
        $this->travelTo($now->copy()->addMicroseconds(100));
        $second = $this->join();
        app(WaitlistService::class)->leave($this->event, $this->type, $first->user);
        $this->travelTo($now->copy()->addMicroseconds(200));
        app(WaitlistService::class)->join($this->event, $this->type, $first->user);
        $this->offer();
        $this->assertSame('offered', $second->fresh()->status);
        $this->assertSame('waiting', $first->fresh()->status);
    }
}
