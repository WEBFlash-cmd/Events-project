<?php

namespace App\Http\Requests\Venue;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreVenueRequest extends FormRequest
{
    public function messages(): array
    {
        return [
            'name.required' => 'Введите название площадки.',
            'name.string' => 'Название должно быть текстом.',
            'name.max' => 'Название не должно превышать 255 символов.',
            'address.required' => 'Введите адрес площадки.',
            'address.string' => 'Адрес должен быть текстом.',
            'address.max' => 'Адрес не должен превышать 255 символов.',
            'description.string' => 'Описание должно быть текстом.',
            'capacity.required' => 'Укажите вместимость площадки.',
            'capacity.integer' => 'Вместимость должна быть целым числом.',
            'capacity.min' => 'Вместимость должна быть не меньше 1.',
            'capacity.max' => 'Вместимость не должна превышать 2147483647.',
        ];
    }

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
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'capacity' => ['required', 'integer', 'min:1', 'max:2147483647'],
        ];
    }
}
