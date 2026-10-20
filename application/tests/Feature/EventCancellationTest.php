<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Events\OrderEventCancelled;
use App\Events\OrderPaid;
use App\Listeners\SendEventCancellation;
use App\Listeners\SendPurchaseConfirmation;
use App\Mail\EventCancellation;
use App\Models\Order;
use App\Models\User;
use App\Services\EventCancellationService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class EventCancellationTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    private function context(string $status = 'paid'): array
    {
        [$buyer, $event, $type] = $this->createOrderContext('1.00');
        $order = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id,
            'total_amount' => '2.00', 'status' => $status, 'expires_at' => now()->addMinutes(15)]);
        $item = $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => 2, 'unit_price' => '1.00']);
        $type->decrement('quantity', 2);
        $order->payment()->create(['reference' => (string) Str::uuid(), 'cashbox_id' => 1,
            'amount' => 200, 'status' => $status === 'paid' ? 'paid' : 'pending']);
        if ($status === 'paid') {
            foreach ([1, 2] as $sequence) {
                $order->tickets()->create(['number' => (string) Str::uuid(), 'user_id' => $buyer->id,
                    'event_id' => $event->id, 'ticket_type_id' => $type->id, 'order_item_id' => $item->id,
                    'sequence' => $sequence, 'status' => 'active']);
            }
        }
        return [$buyer, $event, $type, $order];
    }

    public function testCancellationRetainsHistoryFlagsRefundAndBlocksSalesAndEntry(): void
    {
        Mail::fake();
        [$buyer, $event, $type, $order] = $this->context();
        $used = $order->tickets()->first();
        $used->status = 'used';
        $used->checked_in_at = now();
        $used->checked_in_by = $event->organizer_id;
        $used->save();
        Sanctum::actingAs($event->organizer);
        $this->postJson("/api/events/{$event->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('refund_required', $order->payment->fresh()->status);
        $this->assertTrue($order->payment->fresh()->refund_required);
        $this->assertSame(2, $order->tickets()->where('refund_required', true)->count());
        $this->assertSame('used', $used->fresh()->status);
        $this->assertNotNull($used->fresh()->checked_in_at);
        $active = $order->tickets()->where('id', '!=', $used->id)->first();
        $this->assertSame('cancelled', $active->status);
        $this->assertSame(8, $type->fresh()->quantity);
        $this->postJson("/api/events/{$event->id}/tickets/{$active->id}/check-in")->assertConflict();
        Sanctum::actingAs($buyer);
        $this->postJson("/api/events/{$event->id}/orders", ['items' => [
            ['ticket_type_id' => $type->id, 'quantity' => 1],
        ]])->assertUnprocessable();
        $this->getJson("/api/orders/{$order->id}")->assertOk();
        Mail::assertSent(EventCancellation::class, 1);
    }

    public function testPendingReservationReleasedOnceAndNotificationNotRepeated(): void
    {
        Event::fake([OrderEventCancelled::class]);
        [, $event, $type, $order] = $this->context('pending');
        $service = app(EventCancellationService::class);
        $service->cancel($event, $event->organizer);
        $service->cancel($event, $event->organizer);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $type->fresh()->quantity);
        $this->assertSame(0, $order->tickets()->count());
        $this->assertFalse($order->payment->fresh()->refund_required);
        Event::assertDispatchedTimes(OrderEventCancelled::class, 1);
    }

    public function testLatePaymentAfterCancellationRequiresRefundWithoutIssuingTickets(): void
    {
        Mail::fake();
        $this->configurePaymentGateway();
        [, $event, $type, $order] = $this->context('pending');
        app(EventCancellationService::class)->cancel($event, $event->organizer);
        $data = ['order_id' => $order->payment->reference, 'amount' => 200, 'status' => 1];
        app(PaymentService::class)->processWebhook($data);
        app(PaymentService::class)->processWebhook($data);
        $this->assertTrue($order->payment->fresh()->refund_required);
        $this->assertSame(0, $order->tickets()->count());
        $this->assertSame(10, $type->fresh()->quantity);
    }

    public function testParticipantAndOtherOrganizerDeniedButAdminAllowed(): void
    {
        Mail::fake();
        [$buyer, $event] = $this->context();
        foreach ([$buyer, User::factory()->create(['role' => UserRole::ORGANIZER])] as $user) {
            Sanctum::actingAs($user);
            $this->postJson("/api/events/{$event->id}/cancel")->assertForbidden();
        }
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
        $this->postJson("/api/events/{$event->id}/cancel")->assertOk();
    }

    public function testDraftFinishedAndEndedEventsCannotBeCancelled(): void
    {
        [, $event] = $this->context();
        Sanctum::actingAs($event->organizer);
        foreach (['draft', 'finished'] as $status) {
            $event->update(['status' => $status]);
            $this->postJson("/api/events/{$event->id}/cancel")->assertConflict();
        }
        $event->update(['status' => 'published', 'ends_at' => now()]);
        $this->postJson("/api/events/{$event->id}/cancel")->assertConflict();
    }

    public function testNotificationsAreIdempotentAndPurchaseConfirmationIsSkipped(): void
    {
        Mail::fake();
        [, $event, , $order] = $this->context();
        app(EventCancellationService::class)->cancel($event, $event->organizer);
        app(SendEventCancellation::class)->handle(new OrderEventCancelled($order));
        app(SendPurchaseConfirmation::class)->handle(new OrderPaid($order));
        Mail::assertSent(EventCancellation::class, 1);
        $this->assertNotNull($order->fresh()->event_cancellation_sent_at);
        $this->assertNull($order->fresh()->purchase_confirmation_sent_at);
        $html = (new EventCancellation($order->fresh()))->render();
        $this->assertStringContainsString('Деньги ещё не возвращены', $html);
    }

    public function testGuestsCannotCancel(): void
    {
        [, $event] = $this->context();
        $this->postJson("/api/events/{$event->id}/cancel")->assertUnauthorized();
    }

    public function testFailedMailCanBeRetriedWithoutUndoingCancellation(): void
    {
        Mail::fake();
        $mailFake = Mail::getFacadeRoot();
        \Illuminate\Support\Facades\Queue::fake();
        [, $event, , $order] = $this->context();
        app(EventCancellationService::class)->cancel($event, $event->organizer);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        try {
            app(SendEventCancellation::class)->handle(new OrderEventCancelled($order));
            $this->fail('Mail failure should be retried by the queue.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SMTP unavailable', $exception->getMessage());
        }
        $this->assertNull($order->fresh()->event_cancellation_sent_at);
        $this->assertSame('cancelled', $event->fresh()->status->value);
        Mail::swap($mailFake);
        app(SendEventCancellation::class)->handle(new OrderEventCancelled($order));
        Mail::assertSent(EventCancellation::class, 1);
    }

    public function testOwnerNeedsPermissionAndCannotRepublishCancelledEvent(): void
    {
        Mail::fake();
        [, $event] = $this->context();
        $actor = $event->organizer;
        $permission = \App\Models\Permission::where('name', 'events.cancel')->firstOrFail();
        $actor->rbacRole->permissions()->detach($permission->id);
        Sanctum::actingAs($actor);
        $this->postJson("/api/events/{$event->id}/cancel")->assertForbidden();
        $actor->rbacRole->permissions()->attach($permission->id);
        $this->postJson("/api/events/{$event->id}/cancel")->assertOk();
        $this->postJson("/api/events/{$event->id}/publish")->assertUnprocessable();
        $this->assertSame('cancelled', $event->fresh()->status->value);
    }
}
