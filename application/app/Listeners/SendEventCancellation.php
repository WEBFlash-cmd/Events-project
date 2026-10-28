<?php

namespace App\Listeners;

use App\Enums\EventStatus;
use App\Events\OrderEventCancelled;
use App\Mail\EventCancellation;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendEventCancellation implements ShouldQueueAfterCommit, ShouldBeUnique
{
    public string $queue = 'notifications';
    public int $tries = 3;
    public array $backoff = [10, 60];
    public int $uniqueFor = 300;

    public function uniqueId(OrderEventCancelled $event): string
    {
        return (string) $event->order->id;
    }

    public function handle(OrderEventCancelled $event): void
    {
        DB::transaction(function () use ($event) {
            $order = Order::query()->lockForUpdate()->findOrFail($event->order->id);
            $order->load(['event', 'user', 'payment']);
            if ($order->event->status !== EventStatus::CANCELLED || $order->event_cancellation_sent_at !== null) {
                return;
            }
            Mail::to($order->user->email)->send(new EventCancellation($order));
            $order->event_cancellation_sent_at = now();
            $order->save();
        });
    }
}
