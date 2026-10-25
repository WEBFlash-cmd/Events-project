<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Events\EventPublished;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($event, $data) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $event->fill($data);

            $protectedChanges = array_filter(['starts_at', 'ends_at', 'venue_id', 'allows_ticket_cancellation'],
                fn ($field) => $event->isDirty($field));
            if ($protectedChanges !== [] && (Order::query()->where('event_id', $event->id)
                ->where('status', 'paid')->exists() || $event->tickets()->exists())) {
                throw ValidationException::withMessages(array_fill_keys($protectedChanges,
                    'После продажи билетов нельзя изменять дату, место и условия отмены.'));
            }

            // Partial updates must be checked against the current locked record.
            if ($event->isDirty('starts_at') && $event->starts_at->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['starts_at' => 'Дата начала должна быть в будущем.']);
            }
            if ($event->ends_at->lessThanOrEqualTo($event->starts_at)) {
                throw ValidationException::withMessages(['ends_at' => 'Дата окончания должна быть позже даты начала.']);
            }

            if ($event->isDirty('venue_id')) {
                $venue = Venue::query()->findOrFail($event->venue_id);
                $held = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->where('orders.event_id', $event->id)->whereIn('orders.status', ['pending', 'paid'])
                    ->sum('order_items.quantity');
                $held -= $event->tickets()->whereNotNull('cancelled_at')
                    ->whereHas('order', fn ($query) => $query->where('status', 'paid'))->count();
                if ($event->ticketTypes()->sum('quantity') + $held > $venue->capacity) {
                    throw ValidationException::withMessages([
                        'venue_id' => 'Вместимость места меньше общего количества доступных и зарезервированных билетов.',
                    ]);
                }
            }

            $event->save();

            return $event;
        }, 3);
    }

    public function publishEvent(Event $event): Event
    {
        return DB::transaction(function () use ($event) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            return $this->publishLockedEvent($event);
        }, 3);
    }

    private function publishLockedEvent(Event $event): Event
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
