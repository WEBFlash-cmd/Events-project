<?php

namespace App\Http\Requests\Event;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'organizer_id' => ['prohibited'],
            'status' => ['prohibited'],
            'allows_ticket_cancellation' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Заполните поле «:attribute».',
            'string' => 'Поле «:attribute» должно содержать текст.',
            'title.max' => 'Название не должно превышать 255 символов.',
            'date' => 'Укажите корректные дату и время в поле «:attribute».',
            'starts_at.after' => 'Дата начала должна быть в будущем.',
            'ends_at.after' => 'Дата окончания должна быть позже даты начала.',
            'exists' => 'Выбранное значение поля «:attribute» больше недоступно.',
            'integer' => 'Выберите корректное значение поля «:attribute».',
            'boolean' => 'Укажите, разрешена ли отмена билетов.',
            'prohibited' => 'Поле «:attribute» нельзя изменять через эту форму.',
        ];
    }

    public function attributes(): array
    {
        return ['title' => 'Название', 'description' => 'Описание', 'starts_at' => 'Начало',
            'ends_at' => 'Окончание', 'venue_id' => 'Площадка', 'category_id' => 'Категория',
            'organizer_id' => 'Организатор', 'status' => 'Статус', 'allows_ticket_cancellation' => 'Отмена билетов'];
    }
}
