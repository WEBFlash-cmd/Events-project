<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Events\EventStarted;
use App\Events\EventAvailabilityChanged;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

class EventLifecycleService
{
    public function synchronize(Event $event): bool
    {
        return DB::transaction(function () use ($event) {
            $event = Event::query()->lockForUpdate()->find($event->id);
            $now = now();
            if ($event === null || $event->status !== EventStatus::PUBLISHED || $event->starts_at->greaterThan($now)) {
                return false;
            }

            $changed = false;
            if ($event->started_at === null) {
                $event->started_at = $event->starts_at;
                $event->save();
                EventAvailabilityChanged::dispatch($event);
                EventStarted::dispatch($event);
                $changed = true;
            }
            if ($event->ends_at->lessThanOrEqualTo($now)) {
                $event->status = EventStatus::FINISHED;
                $event->save();
                $changed = true;
            }

            return $changed;
        }, 3);
    }
}
