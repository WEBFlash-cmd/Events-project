<?php

namespace App\Jobs;

use App\Enums\EventStatus;
use App\Mail\EventReminder;
use App\Models\Event;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendEventReminder implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [10, 60];
    public int $uniqueFor = 3600;

    public function __construct(public int $orderId, public int $eventId)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function handle(): void
    {
        // Same lock order as cancellation and check-in; recheck at actual send time.
        DB::transaction(function () {
            $event = Event::query()->lockForUpdate()->find($this->eventId);
            $order = Order::query()->lockForUpdate()->find($this->orderId);
            $now = now();
            if ($event === null || $order === null || $order->event_id !== $event->id
                || $order->status !== 'paid' || $order->event_reminder_sent_at !== null
                || $event->status !== EventStatus::PUBLISHED
                || $event->starts_at->lessThanOrEqualTo($now)
                || $event->starts_at->greaterThan($now->copy()->addDay())) {
                return;
            }

            $order->load(['user', 'tickets' => fn ($query) => $query->where('status', 'active')
                ->where('refund_required', false)->with('ticketType')]);
            if ($order->tickets->isEmpty()) {
                return;
            }
            $order->setRelation('event', $event->load('venue'));
            Mail::to($order->user->email)->send(new EventReminder($order));
            $order->event_reminder_sent_at = now();
            $order->save();
        });
    }
}
