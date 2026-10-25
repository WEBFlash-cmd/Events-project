<?php

namespace App\Http\Requests;

use App\Data\Auth\RegistrationData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class AuthRegistration extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge([
                'email' => Str::lower($this->input('email')),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            "email" => "bail|required|string|email|max:255|unique:users,email",
            "password" => ['bail', 'required', 'string', 'max:72',
                function (string $attribute, string $value, \Closure $fail) {
                    if (strlen($value) > 72 || str_contains($value, "\0")) {
                        $fail('Пароль должен содержать не более 72 байт и не содержать нулевых символов.');
                    }
                }],
        ];
    }

    public function toDTO(): RegistrationData
    {
        return new RegistrationData(...$this->validated());
    }
}
