<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TicketCancellationService
{
    public function cancel(Ticket $ticket, User $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $actor) {
            $event = Event::query()->lockForUpdate()->findOrFail($ticket->event_id);
            $order = Order::query()->lockForUpdate()->findOrFail($ticket->order_id);
            $payment = $order->payment()->lockForUpdate()->first();
            $type = $event->ticketTypes()->lockForUpdate()->findOrFail($ticket->ticket_type_id);
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            Gate::forUser($actor)->authorize('cancel', $ticket);

            if ($ticket->cancelled_at !== null) {
                return $ticket->load(['event.venue', 'ticketType', 'order:id,status']);
            }

            abort_unless($event->status === EventStatus::PUBLISHED && $event->allows_ticket_cancellation
                && $event->starts_at->isFuture(), 409, 'Ticket cancellation is not allowed for this event.');
            abort_unless($order->status === 'paid' && $order->event_id === $event->id
                && $order->user_id === $ticket->user_id && $ticket->event_id === $event->id,
                409, 'Only an owned ticket from a paid order can be cancelled.');
            abort_unless($ticket->status === 'active' && $ticket->checked_in_at === null && !$ticket->refund_required,
                409, 'Only an unused active ticket can be cancelled.');
            abort_unless($payment !== null && in_array($payment->status, ['paid', 'refund_required'], true)
                && $payment->amount === (int) str_replace('.', '', $order->total_amount),
                409, 'A confirmed payment is required.');

            $item = $order->items()->where('ticket_type_id', $type->id)->findOrFail($ticket->order_item_id);
            $amount = (int) str_replace('.', '', $item->unit_price);
            $refund = $payment->refund_amount + $amount;
            abort_if($refund > $payment->amount, 409, 'Refund amount exceeds the payment.');

            $ticket->status = 'cancelled';
            $ticket->refund_required = $amount > 0;
            $ticket->cancelled_at = now();
            $ticket->save();
            $type->increment('quantity');
            $payment->update(['status' => $refund > 0 ? 'refund_required' : 'paid',
                'refund_required' => $refund > 0, 'refund_amount' => $refund]);

            return $ticket->load(['event.venue', 'ticketType', 'order:id,status']);
        }, 3);
    }
}
