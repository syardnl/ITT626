<?php

namespace App\Http\Requests\Admin;

use App\Enums\LocationType;
use App\Enums\ResidentType;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/** Rules shared by creating and editing an account. */
trait AccountAttributeRules
{
    /** Emails are stored trimmed and lower-case, so "Rina@Example.test" and "rina@example.test" are one account. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    protected function emailRules(?User $ignore = null): array
    {
        return ['string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignore)];
    }

    /** A Resident lives in exactly one active Unit. */
    protected function activeUnit(): Exists
    {
        return Rule::exists('locations', 'id')->where('type', LocationType::Unit->value)->where('active', true);
    }

    protected function residentTypeRule(): mixed
    {
        return Rule::enum(ResidentType::class);
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Another account already uses this email.',
            'unit_id.required' => 'A Resident must be linked to a Unit.',
            'unit_id.exists' => 'Choose an active Unit.',
            'unit_id.prohibited' => 'Only Resident accounts are linked to a Unit.',
            'resident_type.required' => 'Choose whether the Resident is an Owner, Tenant or Student.',
            'resident_type.prohibited' => 'Only Resident accounts have a Resident Type.',
        ];
    }
}
