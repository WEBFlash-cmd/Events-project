<?php

namespace App\Http\Requests\TicketType;

class UpdateTicketQuantityRequest extends UpdateTicketTypeRequest
{
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'expected_quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'event_id' => ['prohibited'],
        ];
    }
}
