<?php

namespace App\Console\Commands;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Services\EventLifecycleService;
use Illuminate\Console\Command;

class SyncEventStatuses extends Command
{
    protected $signature = 'events:sync-statuses';

    protected $description = 'Record event starts and mark ended published events as finished';

    public function handle(EventLifecycleService $lifecycle): int
    {
        $now = now();
        $updated = 0;
        Event::query()->where('status', EventStatus::PUBLISHED)->where('starts_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('started_at')->orWhere('ends_at', '<=', $now))
            ->chunkById(200, function ($events) use ($lifecycle, &$updated) {
                foreach ($events as $event) {
                    if ($lifecycle->synchronize($event)) {
                        $updated++;
                    }
                }
            });
        $this->info("Updated events: {$updated}");

        return self::SUCCESS;
    }
}
