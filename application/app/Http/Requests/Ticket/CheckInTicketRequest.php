<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

class CheckInTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['prohibited'],
            'checked_in_at' => ['prohibited'],
            'checked_in_by' => ['prohibited'],
        ];
    }
}
