<?php

namespace App\Http\Requests\TicketType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', [
            $this->route('ticketType'),
            $this->route('event'),
        ]) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:0', 'max:2147483647'],
            'event_id' => ['prohibited'],
        ];
    }
}
