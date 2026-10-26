<?php

namespace App\Listeners;

use App\Events\EventCancelled;
use App\Events\EventStarted;
use App\Events\OrderPaid;
use App\Events\TicketAvailabilityChanged;
use App\Services\WaitlistService;

class ProcessWaitlist
{
    public function __construct(private WaitlistService $waitlist)
    {
    }

    public function handle(TicketAvailabilityChanged|EventCancelled|EventStarted|OrderPaid $event): void
    {
        $this->waitlist->process($event instanceof OrderPaid ? $event->order->event : $event->event);
    }
}
