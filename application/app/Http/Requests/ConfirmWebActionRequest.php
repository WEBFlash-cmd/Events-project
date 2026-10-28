<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmWebActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // Action permissions are checked by route middleware.
    }

    private function confirmation(): array
    {
        return match ($this->route()->getName()) {
            'admin.categories.destroy' => ['confirm_delete', 'Подтвердите удаление категории.'],
            'admin.venues.destroy' => ['confirm_delete', 'Подтвердите удаление площадки.'],
            'admin.events.destroy' => ['confirm_delete', 'Подтвердите удаление мероприятия.'],
            'admin.users.block' => ['confirm_block', 'Подтвердите блокировку пользователя.'],
            'admin.users.unblock' => ['confirm_unblock', 'Подтвердите разблокировку пользователя.'],
            'organizer.events.cancel' => ['confirm_cancel', 'Подтвердите отмену мероприятия.'],
        };
    }

    public function rules(): array
    {
        [$field] = $this->confirmation();

        return [$field => ['required', 'accepted']];
    }

    public function messages(): array
    {
        [$field, $message] = $this->confirmation();

        return [$field.'.required' => $message, $field.'.accepted' => $message];
    }
}
