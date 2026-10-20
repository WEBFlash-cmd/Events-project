<?php

namespace Tests\Feature;

use App\Jobs\SendEventReminder;
use App\Mail\EventReminder;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class EventReminderTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Mail::fake();
    }

    private function order(int $secondsUntilStart = 86400): Order
    {
        [$buyer, $event, $type] = $this->createOrderContext();
        $event->update(['starts_at' => now()->addSeconds($secondsUntilStart),
            'ends_at' => now()->addSeconds($secondsUntilStart)->addHours(2)]);
        $order = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id,
            'status' => 'paid', 'total_amount' => '100000.00']);
        $item = $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => 1, 'unit_price' => '100000.00']);
        $order->tickets()->create(['number' => (string) Str::uuid(), 'user_id' => $buyer->id,
            'event_id' => $event->id, 'ticket_type_id' => $type->id, 'order_item_id' => $item->id,
            'sequence' => 1, 'status' => 'active']);

        return $order;
    }

    private function job(Order $order): SendEventReminder
    {
        return new SendEventReminder($order->id, $order->event_id);
    }

    public function testCommandQueuesEligibleOrdersOnNotificationsQueue(): void
    {
        Queue::fake();
        $order = $this->order();
        $this->artisan('events:remind')->assertSuccessful();
        Queue::assertPushedOn('notifications', SendEventReminder::class, fn ($job) => $job->orderId === $order->id);
        Mail::assertNothingSent();
    }

    public function testRepeatedCommandDoesNotDuplicatePendingJob(): void
    {
        Queue::fake();
        $this->order();
        $this->artisan('events:remind')->assertSuccessful();
        $this->artisan('events:remind')->assertSuccessful();
        Queue::assertPushed(SendEventReminder::class, 1);
    }

    public function testWorkerSendsOneReminderWithEventLocationAndTicket(): void
    {
        $order = $this->order();
        $this->job($order)->handle();
        $this->job($order)->handle();
        Mail::assertSent(EventReminder::class, function ($mail) use ($order) {
            $mail->assertTo($order->user->email);
            $mail->assertSeeInHtml($order->event->title);
            $mail->assertSeeInHtml($order->event->starts_at->format('d.m.Y H:i'));
            $mail->assertSeeInHtml($order->event->venue->address);
            $mail->assertSeeInHtml($order->tickets->first()->number);
            return true;
        });
        Mail::assertSent(EventReminder::class, 1);
        $this->assertTrue($order->fresh()->event_reminder_sent_at->equalTo(now()));
    }

    public static function excluded(): array
    {
        return [
            'more than 24 hours' => ['early'], 'already started' => ['started'],
            'draft' => ['draft'], 'cancelled' => ['cancelled'], 'finished' => ['finished'],
            'pending order' => ['pending'], 'expired order' => ['expired'],
            'cancelled order' => ['cancelled_order'], 'used ticket' => ['used'],
            'cancelled ticket' => ['cancelled_ticket'], 'refund ticket' => ['refund'],
            'already reminded' => ['sent'], 'no tickets' => ['no_tickets'],
        ];
    }

    #[DataProvider('excluded')]
    public function testCommandAndWorkerSkipIneligibleOrders(string $condition): void
    {
        Queue::fake();
        $order = $this->order();
        match ($condition) {
            'early' => $order->event->update(['starts_at' => now()->addDay()->addSecond()]),
            'started' => $order->event->update(['starts_at' => now()]),
            'draft', 'cancelled', 'finished' => $order->event->update(['status' => $condition]),
            'pending', 'expired' => $order->update(['status' => $condition]),
            'cancelled_order' => $order->update(['status' => 'cancelled']),
            'used' => $order->tickets()->update(['status' => 'used']),
            'cancelled_ticket' => $order->tickets()->update(['status' => 'cancelled']),
            'refund' => $order->tickets()->update(['refund_required' => true]),
            'sent' => $order->forceFill(['event_reminder_sent_at' => now()->subMinute()])->save(),
            'no_tickets' => $order->tickets()->delete(),
        };
        $this->artisan('events:remind')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->job($order)->handle();
        Mail::assertNothingSent();
    }

    public function testCancellationWhileQueuedPreventsSending(): void
    {
        $order = $this->order();
        $job = $this->job($order);
        $order->event->update(['status' => 'cancelled']);
        $job->handle();
        Mail::assertNothingSent();
        $this->assertNull($order->fresh()->event_reminder_sent_at);
    }

    public function testDelayedWorkerDoesNotSendAfterStart(): void
    {
        $order = $this->order(60);
        $job = $this->job($order);
        $this->travel(60)->seconds();
        $job->handle();
        Mail::assertNothingSent();
    }

    public function testLatePurchaseWithinReminderWindowIsIncluded(): void
    {
        $order = $this->order(3600);
        $this->artisan('events:remind')->assertSuccessful();
        Mail::assertSent(EventReminder::class, 1);
        $this->assertNotNull($order->fresh()->event_reminder_sent_at);
    }

    public function testSmtpFailureLeavesOrderEligibleForRetry(): void
    {
        $order = $this->order();
        $mailFake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        try {
            $this->job($order)->handle();
            $this->fail('SMTP failure must reach the queue worker.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SMTP unavailable', $exception->getMessage());
        }
        $this->assertNull($order->fresh()->event_reminder_sent_at);
        Mail::swap($mailFake);
        $this->job($order)->handle();
        Mail::assertSent(EventReminder::class, 1);
    }
}
