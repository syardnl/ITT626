<?php

namespace App\Http\Requests\Admin;

use App\Enums\LocationType;
use App\Rules\ValidPlacement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
{
    use LocationAttributeRules;

    public function rules(): array
    {
        $type = LocationType::tryFrom((string) $this->input('type'));

        return [
            'type' => ['required', Rule::enum(LocationType::class)],
            'parent_id' => ['present', 'nullable', 'integer', new ValidPlacement($type)],
            'code' => ['required', ...$this->codeRules()],
            'name' => ['required', 'string', 'max:100'],
            'importance' => $this->importanceRules($type),
        ];
    }
}
