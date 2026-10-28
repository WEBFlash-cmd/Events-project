<?php

namespace App\Http\Requests\Order;

class StoreCheckoutRequest extends StoreOrderRequest
{
    public function rules(): array
    {
        return parent::rules() + ['checkout_reference' => ['required', 'uuid']];
    }
}
