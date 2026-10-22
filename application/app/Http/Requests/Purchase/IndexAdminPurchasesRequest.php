<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAdminPurchasesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $statuses = $this->routeIs('admin.orders.index')
            ? ['pending', 'paid', 'cancelled', 'expired']
            : ['active', 'used', 'cancelled'];

        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'user_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'event_id' => ['sometimes', 'required', 'integer', 'exists:events,id'],
            'status' => ['sometimes', 'required', Rule::in($statuses)],
            'refund_required' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
