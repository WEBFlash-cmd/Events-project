<?php

namespace App\Http\Requests\Auth;

use App\Data\Auth\RegistrationData;
use App\Http\Requests\AuthRegistration;

class BrowserRegistrationRequest extends AuthRegistration
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['password'] = array_merge($rules['password'], ['min:8', 'confirmed']);
        $rules['password_confirmation'] = ['required', 'string'];

        return $rules;
    }

    public function toDTO(): RegistrationData
    {
        // Confirmation belongs to validation, not the registration service payload.
        return new RegistrationData(email: $this->validated('email'), password: $this->validated('password'));
    }
}
