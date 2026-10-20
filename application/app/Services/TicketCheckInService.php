<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TicketCheckInService
{
    public function checkIn(Event $event, Ticket $ticket, User $user): Ticket
    {
        return DB::transaction(function () use ($event, $ticket, $user) {
            // Match payment/reservation lock order, and re-read state after acquiring locks.
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $order = Order::query()->lockForUpdate()->findOrFail($ticket->order_id);
            $ticket = $event->tickets()->lockForUpdate()->findOrFail($ticket->id);
            Gate::forUser($user)->authorize('checkIn', [$ticket, $event]);

            abort_unless($event->status === EventStatus::PUBLISHED && $event->ends_at->isFuture(),
                409, 'Check-in is not available for this event.');
            abort_unless($ticket->order_id === $order->id && $order->event_id === $event->id
                && $order->user_id === $ticket->user_id && $order->status === 'paid',
                409, 'The ticket must belong to a paid order for this event.');
            abort_unless($ticket->status === 'active' && $ticket->checked_in_at === null,
                409, 'The ticket is not active or has already been used.');

            $ticket->status = 'used';
            $ticket->checked_in_at = now();
            $ticket->checked_in_by = $user->id;
            $ticket->save();

            return $ticket->load(['event.venue', 'ticketType', 'order:id,status']);
        }, 3);
    }
}
