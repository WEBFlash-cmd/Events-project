<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketTypeService
{
    public function getTicketTypes(Event $event): Collection
    {
        abort_unless($event->status === EventStatus::PUBLISHED, 404);

        return $event->ticketTypes()->orderBy('id')->get();
    }

    public function createTicketType(Event $event, array $data): TicketType
    {
        return DB::transaction(function () use ($event, $data): TicketType {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);

            $this->ensureCapacity($event, (int) $data['quantity']);
            unset($data['event_id']);

            return $event->ticketTypes()->create($data);
        });
    }

    public function updateTicketType(Event $event, TicketType $ticketType, array $data): TicketType
    {
        return DB::transaction(function () use ($event, $ticketType, $data): TicketType {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $ticketType = $event->ticketTypes()->findOrFail($ticketType->id);

            if (array_key_exists('quantity', $data)) {
                $this->ensureCapacity($event, (int) $data['quantity'], $ticketType->id);
            }

            unset($data['event_id']);
            $ticketType->update($data);

            return $ticketType;
        });
    }

    private function ensureCapacity(Event $event, int $quantity, ?int $exceptId = null): void
    {
        $query = $event->ticketTypes();

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        if ($quantity < 0 || $query->sum('quantity') + $quantity > $event->venue->capacity) {
            throw ValidationException::withMessages([
                'quantity' => 'Количество билетов должно быть неотрицательным, а общее количество не должно превышать вместимость места проведения.',
            ]);
        }
    }
}
