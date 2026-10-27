<?php

namespace App\Listeners;

use App\Events\EventAvailabilityChanged;
use App\Services\WaitlistService;

class ProcessWaitlist
{
    public function __construct(private WaitlistService $waitlist)
    {
    }

    public function handle(EventAvailabilityChanged $event): void
    {
        $this->waitlist->process($event->event);
    }
}
