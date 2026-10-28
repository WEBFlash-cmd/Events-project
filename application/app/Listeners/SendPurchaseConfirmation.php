<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Mail\PurchaseConfirmation;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendPurchaseConfirmation implements ShouldQueueAfterCommit, ShouldBeUnique
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [10, 60];
    public int $uniqueFor = 300;

    public function uniqueId(OrderPaid $event): string
    {
        return (string) $event->order->id;
    }

    public function handle(OrderPaid $event): void
    {
        DB::transaction(function () use ($event) {
            $order = Order::query()->lockForUpdate()->findOrFail($event->order->id);

            if ($order->status !== 'paid' || $order->purchase_confirmation_sent_at !== null
                || $order->event->status === \App\Enums\EventStatus::CANCELLED) {
                return;
            }

            $order->load(['user', 'event.venue', 'tickets.ticketType']);
            if ($order->tickets->isEmpty()) {
                return;
            }

            Mail::to($order->user->email)->send(new PurchaseConfirmation($order));
            $order->purchase_confirmation_sent_at = now();
            $order->save();
        });
    }
}
