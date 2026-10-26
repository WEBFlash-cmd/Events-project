<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\WaitlistEntry;
use App\Services\WaitlistService;
use Illuminate\Console\Command;

class ProcessWaitlists extends Command
{
    protected $signature = 'waitlist:process';
    protected $description = 'Expire waitlist offers and reserve available tickets for the next participants';

    public function handle(WaitlistService $waitlist): int
    {
        Event::query()->whereIn('id', WaitlistEntry::query()->whereIn('status', ['waiting', 'offered'])->select('event_id'))
            ->chunkById(200, function ($events) use ($waitlist) {
                foreach ($events as $event) {
                    $waitlist->process($event);
                }
            });
        $this->info('Waitlists processed.');

        return self::SUCCESS;
    }
}
