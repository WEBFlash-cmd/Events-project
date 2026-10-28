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

    public function messages(): array
    {
        return [
            'required' => 'Заполните поле «:attribute».', 'string' => 'Поле «:attribute» должно содержать текст.',
            'name.max' => 'Название не должно превышать 255 символов.',
            'numeric' => 'Укажите число в поле «:attribute».', 'decimal' => 'В цене допускается не более двух знаков после точки.',
            'integer' => 'Поле «:attribute» должно содержать целое число.', 'min' => 'Поле «:attribute» не может быть меньше :min.',
            'max' => 'Поле «:attribute» не может быть больше :max.', 'prohibited' => 'Поле «:attribute» нельзя передавать в этой форме.',
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'Название', 'description' => 'Описание', 'price' => 'Цена',
            'quantity' => 'Свободные места', 'event_id' => 'Мероприятие', 'expected_quantity' => 'Исходный остаток'];
    }
}
