<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class IndexAdminCatalogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Permissions are checked by route middleware.
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:150'], 'page' => ['sometimes', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return [
            'search.string' => 'Поисковый запрос должен быть текстом.',
            'search.max' => 'Поисковый запрос не должен превышать 150 символов.',
            'page.integer' => 'Номер страницы должен быть целым числом.',
            'page.min' => 'Номер страницы должен быть не меньше 1.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route($this->routeIs('admin.categories.*') ? 'admin.categories.index' : 'admin.venues.index');
    }
}
