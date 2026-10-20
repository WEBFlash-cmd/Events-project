<?php

namespace App\Http\Requests\Payment;

use App\Services\PaymentSignature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WebhookRequest extends FormRequest
{
    public function authorize(PaymentSignature $signature): bool
    {
        return $signature->verify(
            $this->all(), (string) $this->header('X-Signature'), (string) config('payments.secret'),
        );
    }

    public function rules(): array
    {
        return [
            'order_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'status' => ['required', 'integer', Rule::in([1, 2])],
        ];
    }
}
