<?php

namespace App\Http\Requests\Waitlist;

use Illuminate\Foundation\Http\FormRequest;

class WaitlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['prohibited'], 'event_id' => ['prohibited'], 'ticket_type_id' => ['prohibited'],
            'order_id' => ['prohibited'], 'status' => ['prohibited'], 'queued_at' => ['prohibited'],
            'expires_at' => ['prohibited'], 'quantity' => ['prohibited'], 'price' => ['prohibited'],
        ];
    }
}
