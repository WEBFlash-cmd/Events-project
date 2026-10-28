<?php

namespace App\Http\Requests\Admin;

class UpdateUserRolePageRequest extends UpdateUserRoleRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), ['confirm_role' => ['required', 'accepted']]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'confirm_role.required' => 'Подтвердите смену роли.',
            'confirm_role.accepted' => 'Подтвердите смену роли.',
        ]);
    }
}
