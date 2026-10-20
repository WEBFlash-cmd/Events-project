<?php

namespace App\Console\Commands;

use App\Enums\EventStatus;
use App\Jobs\SendEventReminder;
use App\Models\Order;
use Illuminate\Console\Command;

class SendEventReminders extends Command
{
    protected $signature = 'events:remind';

    protected $description = 'Queue reminders for paid orders whose event starts within 24 hours';

    public function handle(): int
    {
        $now = now();
        Order::query()->where('status', 'paid')->whereNull('event_reminder_sent_at')
            ->whereHas('event', fn ($query) => $query->where('status', EventStatus::PUBLISHED)
                ->where('starts_at', '>', $now)->where('starts_at', '<=', $now->copy()->addDay()))
            ->whereHas('tickets', fn ($query) => $query->where('status', 'active')->where('refund_required', false))
            ->select(['id', 'event_id'])->chunkById(200, function ($orders) {
                foreach ($orders as $order) {
                    SendEventReminder::dispatch($order->id, $order->event_id);
                }
            });

        $this->info('Event reminders queued for eligible orders.');

        return self::SUCCESS;
    }
}
