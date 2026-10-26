<?php

namespace App\Jobs;

use App\Enums\EventStatus;
use App\Mail\WaitlistOffer;
use App\Models\Event;
use App\Models\Order;
use App\Models\WaitlistEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendWaitlistOffer implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [10, 60];

    public function __construct(public int $entryId, public int $orderId, public int $eventId)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function handle(): void
    {
        DB::transaction(function () {
            $event = Event::query()->lockForUpdate()->find($this->eventId);
            $order = Order::query()->lockForUpdate()->find($this->orderId);
            $entry = WaitlistEntry::query()->lockForUpdate()->find($this->entryId);
            if ($event === null || $order === null || $entry === null
                || $entry->event_id !== $event->id || $entry->order_id !== $order->id
                || $entry->status !== 'offered' || $entry->notified_at !== null
                || $order->status !== 'pending' || !$order->expires_at->isFuture()
                || $event->status !== EventStatus::PUBLISHED || !$event->starts_at->isFuture()
                || $entry->user->is_blocked) {
                return;
            }
            $entry->setRelation('order', $order);
            $entry->setRelation('event', $event);
            $entry->load('ticketType');
            Mail::to($entry->user->email)->send(new WaitlistOffer($entry));
            $entry->update(['notified_at' => now()]);
        });
    }
}
