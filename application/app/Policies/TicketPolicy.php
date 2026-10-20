<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\Event;
use App\Models\User;

class TicketPolicy
{
    public function checkIn(User $user, Ticket $ticket, Event $event): bool
    {
        return $ticket->event_id === $event->id
            && ($user->hasPermission('tickets.check-in-any')
                || ($event->organizer_id === $user->id && $user->hasPermission('tickets.check-in')));
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $ticket->user_id === $user->id || $user->hasPermission('tickets.view-any');
    }
}
