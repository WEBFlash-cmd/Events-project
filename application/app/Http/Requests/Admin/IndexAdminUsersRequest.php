<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAdminUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.view') ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:150'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'state' => ['nullable', Rule::in(['active', 'blocked'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['search.string' => 'Введите имя или email текстом.', 'search.max' => 'Поисковый запрос не должен превышать 150 символов.',
            'role.enum' => 'Выберите роль из списка.', 'state.in' => 'Выберите состояние из списка.',
            'page.integer' => 'Некорректный номер страницы.', 'page.min' => 'Номер страницы должен быть больше нуля.'];
    }

    protected function getRedirectUrl(): string
    {
        return route('admin.users.index');
    }
}
