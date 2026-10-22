<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['bail', 'required', 'string', 'min:8', 'max:72', 'confirmed', 'different:current_password',
                function (string $attribute, string $value, \Closure $fail) {
                    if (strlen($value) > 72 || str_contains($value, "\0")) {
                        $fail('Пароль должен содержать не более 72 байт и не содержать нулевых символов.');
                    }
                }],
            'password_confirmation' => ['required', 'string'],
        ];
    }
}
