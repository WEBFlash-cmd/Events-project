<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Jobs\SendWaitlistOffer;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\DB;

class WaitlistService
{
    public function __construct(private OrderReservationService $reservations, private PaymentService $payments)
    {
    }

    public function join(Event $event, TicketType $type, User $user): WaitlistEntry
    {
        $this->process($event);

        return DB::transaction(function () use ($event, $type, $user) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $type = $event->ticketTypes()->lockForUpdate()->findOrFail($type->id);
            abort_unless($event->status === EventStatus::PUBLISHED && $event->starts_at->isFuture(),
                409, 'The waitlist is closed for this event.');
            $entry = WaitlistEntry::query()->where('user_id', $user->id)->where('ticket_type_id', $type->id)->first();
            if ($entry !== null && in_array($entry->status, ['waiting', 'offered'], true)) {
                return $entry->load('order');
            }
            abort_unless($type->quantity === 0 && (int) str_replace('.', '', $type->price) > 0,
                409, 'The waitlist is only available for sold-out paid ticket types.');

            return WaitlistEntry::query()->updateOrCreate(
                ['user_id' => $user->id, 'ticket_type_id' => $type->id],
                ['event_id' => $event->id, 'status' => 'waiting', 'queued_at' => now(),
                    'order_id' => null, 'notified_at' => null],
            );
        }, 3);
    }

    public function ownEntry(Event $event, TicketType $type, User $user): WaitlistEntry
    {
        abort_unless($type->event_id === $event->id, 404);

        return WaitlistEntry::query()->where('event_id', $event->id)->where('ticket_type_id', $type->id)
            ->where('user_id', $user->id)->firstOrFail()->load(['order.items', 'order.payment']);
    }

    public function leave(Event $event, TicketType $type, User $user): void
    {
        DB::transaction(function () use ($event, $type, $user) {
            Event::query()->lockForUpdate()->findOrFail($event->id);
            $entry = $this->ownEntry($event, $type, $user);
            if (!in_array($entry->status, ['waiting', 'offered'], true)) {
                return;
            }
            $order = $entry->order;
            $entry->update(['status' => $order?->status === 'paid' ? 'purchased' : 'cancelled']);
            if ($order?->status === 'pending') {
                $this->reservations->cancel($order);
            }
        }, 3);
        $this->process($event);
    }

    public function accept(Event $event, TicketType $type, User $user): Order
    {
        // Expired offers must be released even when checkout below is rejected.
        $this->process($event);

        return DB::transaction(function () use ($event, $type, $user) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $entry = $this->ownEntry($event, $type, $user);
            abort_unless(in_array($entry->status, ['offered', 'purchased'], true) && $entry->order_id !== null,
                409, 'There is no active waitlist offer.');
            $order = Order::query()->lockForUpdate()->findOrFail($entry->order_id);
            if ($order->status === 'paid') {
                return $order->load('payment');
            }
            abort_unless($order->status === 'pending' && $order->expires_at->isFuture()
                && $event->status === EventStatus::PUBLISHED && $event->starts_at->isFuture(),
                409, 'The waitlist offer has expired or the event is closed.');
            if (!$order->payment()->exists()) {
                $this->payments->prepare($order);
            }

            return $order->load('payment');
        }, 3);
    }

    public function hasPriority(Event $event, int $typeId): bool
    {
        return WaitlistEntry::query()->where('event_id', $event->id)->where('ticket_type_id', $typeId)
            ->where('status', 'waiting')->whereHas('user', fn ($query) => $query->where('is_blocked', false))->exists();
    }

    public function process(Event $event): void
    {
        if (!WaitlistEntry::query()->where('event_id', $event->id)->whereIn('status', ['waiting', 'offered'])->exists()) {
            return;
        }

        DB::transaction(function () use ($event) {
            $event = Event::query()->lockForUpdate()->find($event->id);
            if ($event === null) {
                return;
            }
            $open = $event->status === EventStatus::PUBLISHED && $event->starts_at->isFuture();
            $entries = WaitlistEntry::query()->where('event_id', $event->id)
                ->whereIn('status', ['waiting', 'offered'])->with('user')
                ->orderBy('queued_at')->orderBy('id')->get();

            foreach ($entries as $entry) {
                if ($entry->status === 'offered') {
                    $order = Order::query()->lockForUpdate()->findOrFail($entry->order_id);
                    if ($order->status === 'pending') {
                        if (!$open || $entry->user->is_blocked) {
                            $this->reservations->cancel($order);
                        } else {
                            $this->reservations->expire($order);
                        }
                        $order->refresh();
                    }
                    $status = match ($order->status) {
                        'paid' => 'purchased', 'expired' => 'expired', 'cancelled' => 'cancelled', default => 'offered',
                    };
                    if ($status !== 'offered') {
                        $entry->update(['status' => $status]);
                    }
                } elseif (!$open || $entry->user->is_blocked) {
                    $entry->update(['status' => 'cancelled']);
                }
            }

            if (!$open) {
                return;
            }
            $types = $event->ticketTypes()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($entries as $entry) {
                if ($entry->status !== 'waiting') {
                    continue;
                }
                $type = $types->get($entry->ticket_type_id);
                if ((int) str_replace('.', '', $type->price) <= 0) {
                    $entry->update(['status' => 'cancelled']);
                    continue;
                }
                if ($type->quantity < 1) {
                    continue;
                }
                $order = Order::create(['user_id' => $entry->user_id, 'event_id' => $event->id,
                    'total_amount' => $type->price, 'status' => 'pending',
                    'expires_at' => now()->addMinutes(config('orders.reservation_minutes'))->min($event->starts_at)]);
                $order->items()->create(['ticket_type_id' => $type->id, 'quantity' => 1, 'unit_price' => $type->price]);
                $type->decrement('quantity');
                $entry->update(['status' => 'offered', 'order_id' => $order->id, 'notified_at' => null]);
                DB::afterCommit(fn () => SendWaitlistOffer::dispatch($entry->id, $order->id, $event->id));
            }
        }, 3);
    }
}
