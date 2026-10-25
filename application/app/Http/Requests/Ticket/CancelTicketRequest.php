<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

class CancelTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['prohibited'], 'refund_required' => ['prohibited'],
            'refund_amount' => ['prohibited'], 'cancelled_at' => ['prohibited'],
            'user_id' => ['prohibited'], 'event_id' => ['prohibited'], 'order_id' => ['prohibited'],
        ];
    }
}
