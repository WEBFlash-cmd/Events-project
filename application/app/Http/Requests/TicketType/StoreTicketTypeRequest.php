<?php

namespace App\Http\Requests\TicketType;

use App\Models\TicketType;
use Illuminate\Foundation\Http\FormRequest;

class StoreTicketTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [TicketType::class, $this->route('event')]) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'event_id' => ['prohibited'],
        ];
    }
}
