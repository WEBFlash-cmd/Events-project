<?php

namespace Tests\Feature;

use App\Events\OrderEventCancelled;
use App\Mail\EmailVerification;
use App\Mail\EventCancellation;
use App\Mail\PurchaseConfirmation;
use App\Mail\WaitlistOffer;
use App\Models\Order;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\AccountService;
use App\Services\AuthService;
use App\Services\EventCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOrderContext;
use Tests\TestCase;

class NotificationRecoveryTest extends TestCase
{
    use RefreshDatabase, CreatesOrderContext;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        Mail::fake();
    }

    private function order(string $status = 'paid'): Order
    {
        [$buyer, $event, $type] = $this->createOrderContext('1.00');
        $order = Order::create(['user_id' => $buyer->id, 'event_id' => $event->id,
            'total_amount' => '1.00', 'status' => $status, 'expires_at' => now()->addMinutes(15)]);
        $item = $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => 1, 'unit_price' => '1.00']);
        $type->decrement('quantity');
        if ($status === 'paid') {
            $order->tickets()->create(['number' => (string) Str::uuid(), 'user_id' => $buyer->id,
                'event_id' => $event->id, 'ticket_type_id' => $type->id, 'order_item_id' => $item->id,
                'sequence' => 1, 'status' => 'active']);
        }
        return $order;
    }

    private function work(): void
    {
        while ($job = Queue::connection('database')->pop('notifications')) {
            $job->fire();
        }
    }

    public function testMissingPurchaseJobIsRestoredWithoutQueueOrMailDuplicates(): void
    {
        $order = $this->order();
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $this->work();
        $this->assertNotNull($order->fresh()->purchase_confirmation_sent_at);
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertSent(PurchaseConfirmation::class, 1);
    }

    public function testMissingCancellationJobIsRestoredFromTransactionalIntent(): void
    {
        $order = $this->order('pending');
        // Simulate loss of the after-commit event: the database intent still survives.
        $dispatcher = Event::getFacadeRoot();
        Event::fake([OrderEventCancelled::class]);
        app(EventCancellationService::class)->cancel($order->event, $order->event->organizer);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertNotNull($order->fresh()->event_cancellation_requested_at);
        Event::swap($dispatcher);
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $this->work();
        $this->assertNotNull($order->fresh()->event_cancellation_sent_at);
        Mail::assertSent(EventCancellation::class, 1);
    }

    public function testCancellationRecoveryDoesNotNotifyOrdersCancelledBeforeEvent(): void
    {
        $order = $this->order('cancelled');
        $order->event->update(['status' => 'cancelled']);
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertNothingSent();
    }

    public function testMissingWaitlistJobIsRestoredOnlyWhileOfferIsActive(): void
    {
        $order = $this->order('pending');
        $entry = WaitlistEntry::create(['event_id' => $order->event_id, 'user_id' => $order->user_id,
            'ticket_type_id' => $order->items()->first()->ticket_type_id, 'order_id' => $order->id,
            'status' => 'offered', 'queued_at' => now()]);
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $this->work();
        $this->assertNotNull($entry->fresh()->notified_at);
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertSent(WaitlistOffer::class, 1);
    }

    public function testExpiredWaitlistOfferIsNotRequeuedOrSentByAlreadyQueuedJob(): void
    {
        $order = $this->order('pending');
        $entry = WaitlistEntry::create(['event_id' => $order->event_id, 'user_id' => $order->user_id,
            'ticket_type_id' => $order->items()->first()->ticket_type_id, 'order_id' => $order->id,
            'status' => 'offered', 'queued_at' => now()]);
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->travelTo($order->expires_at);
        $this->work();
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertNull($entry->fresh()->notified_at);
        Mail::assertNothingSent();
    }

    public function testLostVerificationJobIsRecoveredOnceAndBlockedUsersAreSkipped(): void
    {
        $user = User::factory()->unverified()->create();
        User::factory()->unverified()->create(['is_blocked' => true]);
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $this->work();
        $this->assertNotNull($user->fresh()->verification_email_sent_at);
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertSent(EmailVerification::class, 1);
    }

    public function testEmailChangeResetsMarkerAndUsesNewAddress(): void
    {
        $user = User::factory()->create();
        $user->verification_email_sent_at = now()->subDay();
        $user->save();
        app(AccountService::class)->updateProfile($user, ['email' => 'changed@example.test', 'current_password' => 'password']);
        $this->assertNull($user->fresh()->verification_email_sent_at);
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $this->work();
        Mail::assertSent(EmailVerification::class, fn ($mail) => $mail->hasTo('changed@example.test'));
        $this->assertNotNull($user->fresh()->verification_email_sent_at);
    }

    public function testPendingUniqueLockExpiresSoLostDispatchCannotSuppressRecoveryForever(): void
    {
        $user = User::factory()->unverified()->create();
        $this->artisan('notifications:recover')->assertSuccessful();
        // Lose the queued row while leaving its temporary uniqueness lock behind.
        DB::table('jobs')->delete();
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->travel(301)->seconds();
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $this->work();
        Mail::assertSent(EmailVerification::class, 1);
        $this->assertNotNull($user->fresh()->verification_email_sent_at);
    }

    public function testRecoveredVerificationStillWorksWithExistingCodeValidation(): void
    {
        $user = User::factory()->unverified()->create();
        $this->artisan('notifications:recover')->assertSuccessful();
        $this->work();
        Mail::assertSent(EmailVerification::class, function ($mail) use ($user) {
            $this->assertSame($user->id, Cache::get('mail-'.$mail->code)['user_id']);
            app(AuthService::class)->verifyEmail($mail->code);
            return true;
        });
        $this->assertNotNull($user->fresh()->email_verified_at);
    }
}
