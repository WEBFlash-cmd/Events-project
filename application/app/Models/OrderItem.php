<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ticket_type_id', 'quantity', 'unit_price'])]
class OrderItem extends Model
{
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'decimal:2'];
    }
}
