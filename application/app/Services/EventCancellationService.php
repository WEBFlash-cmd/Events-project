<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Events\OrderEventCancelled;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class EventCancellationService
{
    public function __construct(private OrderReservationService $reservations)
    {
    }

    public function cancel(Event $event, User $actor): Event
    {
        return DB::transaction(function () use ($event, $actor) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            Gate::forUser($actor)->authorize('cancel', $event);

            if ($event->status === EventStatus::CANCELLED) {
                return $event;
            }

            abort_unless($event->status === EventStatus::PUBLISHED && $event->ends_at->isFuture(),
                409, 'Only published events that have not ended can be cancelled.');

            $event->status = EventStatus::CANCELLED;
            $event->save();

            // The event lock serializes checkout, payment, expiry and check-in.
            Order::query()->where('event_id', $event->id)->orderBy('id')->chunkById(200,
                function ($orders) {
                    foreach ($orders as $order) {
                        $order = Order::query()->lockForUpdate()->findOrFail($order->id);
                        if (!in_array($order->status, ['pending', 'paid'], true)) {
                            continue;
                        }
                        if ($order->status === 'pending') {
                            $this->reservations->cancel($order);
                        } else {
                            $order->payment()->whereIn('status', ['paid', 'refund_required'])->update([
                                'status' => 'refund_required', 'refund_required' => true,
                                'refund_amount' => DB::raw('amount'),
                            ]);
                            $order->tickets()->update(['refund_required' => true]);
                            $order->tickets()->where('status', 'active')->update(['status' => 'cancelled']);
                        }
                        OrderEventCancelled::dispatch($order);
                    }
                });

            return $event;
        }, 3);
    }
}
