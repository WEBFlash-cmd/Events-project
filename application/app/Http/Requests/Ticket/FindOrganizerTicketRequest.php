<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

class FindOrganizerTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['number' => ['sometimes', 'required', 'string', 'max:255', 'uuid']];
    }

    public function messages(): array
    {
        return ['number.required' => 'Введите номер билета.', 'number.string' => 'Введите номер билета текстом.',
            'number.max' => 'Номер билета не должен превышать 255 символов.',
            'number.uuid' => 'Проверьте номер билета: скопируйте его полностью со страницы билета.'];
    }

    protected function getRedirectUrl(): string
    {
        return route('organizer.reports.check-in', $this->route('event'));
    }
}
