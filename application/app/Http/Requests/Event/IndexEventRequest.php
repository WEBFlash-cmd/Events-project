<?php

namespace App\Http\Requests\Event;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexEventRequest extends FormRequest
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
        $dateToRules = ['sometimes', 'date_format:Y-m-d'];

        if ($this->filled('date_from')) {
            $dateToRules[] = 'after_or_equal:date_from';
        }

        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'venue_id' => ['sometimes', 'integer', 'exists:venues,id'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => $dateToRules,
            'sort' => ['sometimes', 'in:date_asc,date_desc'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
