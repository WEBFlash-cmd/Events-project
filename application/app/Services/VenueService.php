<?php

namespace App\Services;

use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VenueService
{
    public function create(array $data): Venue
    {
        return Venue::create($data);
    }

    public function update(Venue $venue, array $data): Venue
    {
        return DB::transaction(function () use ($venue, $data) {
            $venue = Venue::query()->lockForUpdate()->findOrFail($venue->id);
            $venue->fill($data);

            $protectedChanges = array_filter(['address', 'capacity'], fn ($field) => $venue->isDirty($field));
            if ($protectedChanges !== [] && $venue->events()->exists()) {
                throw ValidationException::withMessages(array_fill_keys($protectedChanges,
                    'Площадка используется в мероприятиях. Адрес и вместимость менять нельзя; создайте новую площадку.'));
            }

            $venue->save();

            return $venue;
        }, 3);
    }

    public function delete(Venue $venue): void
    {
        DB::transaction(function () use ($venue) {
            // The row lock also serializes new event references through the foreign key.
            $venue = Venue::query()->lockForUpdate()->findOrFail($venue->id);
            abort_if($venue->events()->exists(), 409, 'Cannot delete a venue that is used by events.');
            $venue->delete();
        }, 3);
    }
}
