<?php

namespace App\Console\Commands;

use App\Enums\EventStatus;
use App\Events\EmailVerificationRequested;
use App\Events\OrderEventCancelled;
use App\Events\OrderPaid;
use App\Jobs\SendWaitlistOffer;
use App\Models\Order;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class RecoverNotifications extends Command
{
    protected $signature = 'notifications:recover';
    protected $description = 'Requeue eligible notifications whose sent marker is still empty';

    public function handle(): int
    {
        $failed = false;
        $scan = function (Builder $query, callable $dispatch) use (&$failed) {
            $query->chunkById(100, function ($batch) use ($dispatch, &$failed) {
                foreach ($batch as $record) {
                    try {
                        $dispatch($record);
                    } catch (Throwable $error) {
                        report($error);
                        $failed = true;
                    }
                }
            });
        };

        $scan(User::query()->whereNull('email_verified_at')->whereNull('verification_email_sent_at')
            ->where('is_blocked', false), fn ($user) => EmailVerificationRequested::dispatch($user));

        $scan(Order::query()->where('status', 'paid')->whereNull('purchase_confirmation_sent_at')
            ->whereHas('event', fn ($query) => $query->where('status', '!=', EventStatus::CANCELLED))
            ->whereHas('tickets'), fn ($order) => OrderPaid::dispatch($order));

        $scan(Order::query()->whereIn('status', ['paid', 'cancelled', 'expired'])
            ->whereNotNull('event_cancellation_requested_at')
            ->whereNull('event_cancellation_sent_at')
            ->whereHas('event', fn ($query) => $query->where('status', EventStatus::CANCELLED)),
            fn ($order) => OrderEventCancelled::dispatch($order));

        $scan(WaitlistEntry::query()->where('status', 'offered')->whereNull('notified_at')
            ->whereHas('user', fn ($query) => $query->where('is_blocked', false))
            ->whereHas('order', fn ($query) => $query->where('status', 'pending')->where('expires_at', '>', now()))
            ->whereHas('event', fn ($query) => $query->where('status', EventStatus::PUBLISHED)->where('starts_at', '>', now())),
            fn ($entry) => SendWaitlistOffer::dispatch($entry->id, $entry->order_id, $entry->event_id));

        $this->info('Unsent notifications checked.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
