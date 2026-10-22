<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($this->user()->id)],
            'current_password' => ['sometimes', 'required', 'string'],
            'id' => ['prohibited'], 'user_id' => ['prohibited'], 'role' => ['prohibited'],
            'role_id' => ['prohibited'], 'is_blocked' => ['prohibited'],
            'email_verified_at' => ['prohibited'], 'password' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (!$this->hasAny(['name', 'email'])) {
                $validator->errors()->add('profile', 'Укажите имя или email для изменения.');
            }
        }];
    }
}
