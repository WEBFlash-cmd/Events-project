<?php

namespace App\Http\Requests\Event;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class UpdateEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string'],
            'starts_at' => ['sometimes', 'required', 'date', 'after:now'],
            'ends_at' => ['sometimes', 'required', 'date'],
            'venue_id' => ['sometimes', 'required', 'integer', 'exists:venues,id'],
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'organizer_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (!$this->hasAny(['starts_at', 'ends_at'])) {
                    return;
                }

                $event = $this->route('event');

                $startsAt = Carbon::parse(
                    $this->input('starts_at', $event->starts_at)
                );

                $endsAt = Carbon::parse(
                    $this->input('ends_at', $event->ends_at)
                );

                if ($endsAt->lessThanOrEqualTo($startsAt)) {
                    $validator->errors()->add(
                        'ends_at',
                        'Дата окончания должна быть позже даты начала.'
                    );
                }
            },
        ];
    }
}
