<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['number', 'user_id', 'event_id', 'ticket_type_id', 'order_id', 'order_item_id', 'sequence', 'status'])]
class Ticket extends Model
{
    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime', 'checked_in_by' => 'integer', 'refund_required' => 'boolean'];
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
