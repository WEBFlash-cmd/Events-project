<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;

class TicketTypePolicy
{
    public function create(User $user, Event $event): bool
    {
        return $user->hasPermission('ticket-types.create-any')
            || (
                $user->hasPermission('ticket-types.create')
                && $event->organizer_id === $user->id
            );
    }

    public function update(User $user, TicketType $ticketType, Event $event): bool
    {
        if ($ticketType->event_id !== $event->id) {
            return false;
        }

        return $user->hasPermission('ticket-types.update-any')
            || (
                $user->hasPermission('ticket-types.update')
                && $event->organizer_id === $user->id
            );
    }
}
