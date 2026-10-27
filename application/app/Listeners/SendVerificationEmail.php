<?php

namespace App\Listeners;

use App\Services\AuthService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

class SendVerificationEmail implements ShouldQueueAfterCommit
{
    public string $queue = 'notifications';
    public int $tries = 3;
    public array $backoff = [10, 60];

    /**
     * Create the event listener.
     */
    public function __construct(private AuthService $authService)
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(Registered $event): void
    {
        $this->authService->sendVerificationEmail($event->user);
    }
}
