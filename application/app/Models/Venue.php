<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'address', 'description', 'capacity'])]
class Venue extends Model
{
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
