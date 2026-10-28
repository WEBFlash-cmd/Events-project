<?php

namespace App\Http\Requests\Event;

class IndexAdminEventPagesRequest extends IndexAdminEventsRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['per_page']);
        foreach (['status', 'organizer_id', 'category_id', 'venue_id', 'search'] as $field) {
            $rules[$field] = array_map(fn ($rule) => $rule === 'required' ? 'nullable' : $rule, $rules[$field]);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'search.string' => 'Поисковый запрос должен быть текстом.',
            'search.max' => 'Поисковый запрос не должен превышать 255 символов.',
            'status.enum' => 'Выберите статус из списка.',
            'organizer_id.integer' => 'ID организатора должен быть целым числом.',
            'organizer_id.exists' => 'Пользователь с таким ID не найден.',
            'category_id.integer' => 'Выберите категорию из списка.',
            'category_id.exists' => 'Категория не найдена.',
            'venue_id.integer' => 'Выберите площадку из списка.',
            'venue_id.exists' => 'Площадка не найдена.',
            'page.integer' => 'Номер страницы должен быть целым числом.',
            'page.min' => 'Номер страницы должен быть не меньше 1.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('admin.events.index');
    }
}
