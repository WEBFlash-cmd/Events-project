<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Events\EventPublished;
use App\Models\Event;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class EventService
{
    public function createEvent(array $data, User $organizer): Event
    {
        $data['organizer_id'] = $organizer->id;
        $data['status'] = EventStatus::DRAFT;

        return Event::create($data);
    }

    public function updateEvent(Event $event, array $data): Event
    {
        $event->update($data);

        return $event;
    }

    public function publishEvent(Event $event): Event
    {
        if ($event->status !== EventStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Опубликовать можно только черновик.',
            ]);
        }

        if ($event->starts_at->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'starts_at' => 'Дата начала должна быть в будущем.',
            ]);
        }

        $event->status = EventStatus::PUBLISHED;
        $event->save();
        EventPublished::dispatch($event);

        return $event;
    }

    public function getPublishedEvents(array $filters = []): LengthAwarePaginator
    {
        $query = Event::query()
            ->where('status', EventStatus::PUBLISHED)
            ->with(['venue', 'category']);

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['venue_id'])) {
            $query->where('venue_id', $filters['venue_id']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $query->where('title', 'ilike', '%' . $filters['search'] . '%');
        }

        if (isset($filters['date_from'])) {
            $query->whereDate('starts_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('starts_at', '<=', $filters['date_to']);
        }

        $sort = $filters['sort'] ?? 'date_asc';
        $direction = in_array($sort, ['date_desc', 'price_desc'], true)
            ? 'desc'
            : 'asc';

        if (in_array($sort, ['price_asc', 'price_desc'], true)) {
            $query->withMin('ticketTypes as min_ticket_price', 'price')
                ->orderByRaw("min_ticket_price {$direction} NULLS LAST");
        } else {
            $query->orderBy('starts_at', $direction);
        }

        return $query
            ->orderBy('id', $direction)
            ->paginate(15)
            ->withQueryString();
    }

    public function showPublishedEvent(Event $event): Event
    {
        abort_unless($event->status === EventStatus::PUBLISHED, 404);

        return $event->load(['venue', 'category']);
    }
}
