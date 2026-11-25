<?php

namespace App\Http\Requests\Admin;

use App\Enums\LocationImportance;
use App\Enums\LocationType;
use App\Models\Location;
use Illuminate\Validation\Rule;

/** Rules shared by creating and editing a Location. */
trait LocationAttributeRules
{
    /** Codes are stored upper-case, so "a-01" and "A-01" are the same code. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    protected function codeRules(?Location $ignore = null): array
    {
        return ['string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9-]*$/', Rule::unique('locations', 'code')->ignore($ignore)];
    }

    protected function importanceRules(?LocationType $type): array
    {
        return [Rule::prohibitedIf($type !== LocationType::CommonArea), Rule::enum(LocationImportance::class)];
    }

    public function messages(): array
    {
        return [
            'type.prohibited' => 'A Location cannot change type; deactivate it and create a new one.',
            'code.regex' => 'The code may contain only letters, digits and hyphens.',
            'importance.prohibited' => 'Importance can only be set on Common Areas.',
        ];
    }
}
