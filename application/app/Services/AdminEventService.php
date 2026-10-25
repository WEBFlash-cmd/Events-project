<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AdminEventService
{
    public function delete(Event $event, User $actor): void
    {
        DB::transaction(function () use ($event, $actor) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            Gate::forUser($actor)->authorize('delete', $event);
            abort_if(Order::query()->where('event_id', $event->id)->exists() || $event->tickets()->exists(),
                409, 'An event with orders or tickets cannot be deleted.');

            $event->ticketTypes()->delete();
            $event->delete();
        }, 3);
    }

    public function index(array $filters): LengthAwarePaginator
    {
        $query = Event::query()->with(['venue', 'category', 'organizer:id,name,email']);
        foreach (['status', 'organizer_id', 'category_id', 'venue_id'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['search'])) {
            $query->where('title', 'ilike', '%'.$filters['search'].'%');
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15)->withQueryString();
    }

    public function show(Event $event): Event
    {
        return $event->load(['venue', 'category', 'organizer:id,name,email', 'ticketTypes']);
    }
}
