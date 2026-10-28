<?php

namespace App\Http\Requests\Venue;

class UpdateVenueRequest extends StoreVenueRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach ($rules as &$fieldRules) {
            array_unshift($fieldRules, 'sometimes');
        }

        return $rules;
    }
}
