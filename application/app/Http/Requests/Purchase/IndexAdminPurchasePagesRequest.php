<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAdminPurchasePagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $orders = $this->routeIs('admin.purchases.orders');
        $rules = [
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:150'],
            'order_id' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
            'status' => ['nullable', Rule::in($orders ? ['pending', 'paid', 'cancelled', 'expired'] : ['active', 'used', 'cancelled'])],
            'refund_required' => ['nullable', 'boolean'],
        ];
        if (!$orders) {
            $rules['number'] = ['nullable', 'string', 'uuid'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'search.string' => 'Введите поисковый запрос текстом.',
            'search.max' => 'Поисковый запрос не должен превышать 150 символов.',
            'order_id.integer' => 'Номер заказа должен быть целым числом.',
            'order_id.min' => 'Номер заказа должен быть не меньше 1.',
            'order_id.max' => 'Номер заказа слишком большой.',
            'user_id.integer' => 'ID покупателя должен быть целым числом.',
            'user_id.exists' => 'Покупатель не найден.',
            'event_id.integer' => 'ID мероприятия должен быть целым числом.',
            'event_id.exists' => 'Мероприятие не найдено.',
            'status.in' => 'Выберите статус из списка.',
            'refund_required.boolean' => 'Выберите признак возврата из списка.',
            'number.string' => 'Введите номер билета текстом.',
            'number.uuid' => 'Номер билета должен быть UUID, например 11111111-1111-4111-8111-111111111111.',
            'page.integer' => 'Номер страницы должен быть целым числом.',
            'page.min' => 'Номер страницы должен быть не меньше 1.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route($this->routeIs('admin.purchases.orders') ? 'admin.purchases.orders' : 'admin.purchases.tickets');
    }
}
