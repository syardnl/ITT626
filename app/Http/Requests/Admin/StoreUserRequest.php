<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    use AccountAttributeRules;

    public function rules(): array
    {
        $resident = $this->input('role') === Role::Resident->value;

        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', ...$this->emailRules()],
            'role' => ['required', Rule::enum(Role::class)],
            'unit_id' => $resident ? ['required', 'integer', $this->activeUnit()] : ['prohibited'],
            'resident_type' => $resident ? ['required', $this->residentTypeRule()] : ['prohibited'],
        ];
    }
}
