<?php

namespace App\Events;

use App\Models\Event;
use Illuminate\Foundation\Events\Dispatchable;

// Synchronous database work: listeners participate in the caller's transaction.
// Public events and notification jobs are dispatched separately after commit.
class EventAvailabilityChanged
{
    use Dispatchable;

    public function __construct(public Event $event)
    {
    }
}
