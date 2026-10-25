<?php

namespace App\Http\Requests\Event;

use App\Enums\EventStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAdminEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'required', Rule::enum(EventStatus::class)],
            'organizer_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'venue_id' => ['sometimes', 'required', 'integer', 'exists:venues,id'],
            'search' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}
