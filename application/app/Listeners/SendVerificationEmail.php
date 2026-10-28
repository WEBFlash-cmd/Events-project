<?php

namespace App\Listeners;

use App\Services\AuthService;
use App\Events\EmailVerificationRequested;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class SendVerificationEmail implements ShouldQueueAfterCommit, ShouldBeUnique
{
    public string $queue = 'notifications';
    public int $tries = 3;
    public array $backoff = [10, 60];
    public int $uniqueFor = 300;

    public function uniqueId(Registered|EmailVerificationRequested $event): string
    {
        return $event->user->id.':'.hash('sha256', $event->user->email);
    }

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
    public function handle(Registered|EmailVerificationRequested $event): void
    {
        $this->authService->sendVerificationEmail($event->user);
    }
}
