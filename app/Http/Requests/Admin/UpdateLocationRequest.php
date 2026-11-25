<?php

namespace App\Http\Requests\Admin;

use App\Models\Location;
use App\Rules\ValidPlacement;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLocationRequest extends FormRequest
{
    use LocationAttributeRules;

    public function rules(): array
    {
        /** @var Location $location */
        $location = $this->route('location');

        return [
            'type' => ['prohibited'],
            'parent_id' => ['sometimes', 'nullable', 'integer', new ValidPlacement($location->type, $location)],
            'code' => ['sometimes', ...$this->codeRules($location)],
            'name' => ['sometimes', 'string', 'max:100'],
            'importance' => $this->importanceRules($location->type),
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
