<?php

namespace App\Services;

use App\Events\TicketAvailabilityChanged;
use App\Events\EventAvailabilityChanged;
use App\Models\Event;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderReservationService
{
    public function cancel(Order $order, bool $processWaitlist = true): Order
    {
        return DB::transaction(function () use ($order, $processWaitlist) {
            // All reservation changes lock the event first, then the order and ticket types.
            Event::query()->lockForUpdate()->findOrFail($order->event_id);
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (in_array($order->status, ['cancelled', 'expired'], true)) {
                return $order->load('items');
            }

            abort_unless($order->status === 'pending', 409, 'Only pending orders can be cancelled.');

            $status = $order->expires_at?->lessThanOrEqualTo(now()) ? 'expired' : 'cancelled';

            return $this->release($order, $status, $processWaitlist);
        }, 3);
    }

    public function expire(Order $order, bool $processWaitlist = true): bool
    {
        return DB::transaction(function () use ($order, $processWaitlist) {
            Event::query()->lockForUpdate()->findOrFail($order->event_id);
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== 'pending'
                || $order->expires_at === null
                || $order->expires_at->isFuture()) {
                return false;
            }

            $this->release($order, 'expired', $processWaitlist);

            return true;
        }, 3);
    }

    private function release(Order $order, string $status, bool $processWaitlist): Order
    {
        $items = $order->items()->orderBy('ticket_type_id')->get();
        $ticketTypes = $order->event->ticketTypes()
            ->whereIn('id', $items->pluck('ticket_type_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($items as $item) {
            $ticketTypes->get($item->ticket_type_id)->increment('quantity', $item->quantity);
        }

        $order->update(['status' => $status]);
        // A caller already processing the whole waitlist must not re-enter it mid-pass.
        if ($processWaitlist) {
            EventAvailabilityChanged::dispatch($order->event);
        }
        TicketAvailabilityChanged::dispatch($order->event);

        return $order->load('items');
    }
}
