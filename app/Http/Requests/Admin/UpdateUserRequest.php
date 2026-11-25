<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    use AccountAttributeRules;

    public function rules(): array
    {
        $user = $this->account();
        $role = Role::tryFrom((string) $this->input('role')) ?? $user->role();

        // Becoming a Resident needs a Unit and Resident Type unless the account already has them.
        $residentRules = fn (bool $alreadySet, array $rules) => $alreadySet ? ['sometimes', 'required', ...$rules] : ['required', ...$rules];

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'required', ...$this->emailRules($user)],
            'role' => ['sometimes', 'required', Rule::enum(Role::class)],
            'unit_id' => $role === Role::Resident
                ? $residentRules($user->unit_id !== null, ['integer', $this->activeUnit()])
                : ['prohibited'],
            'resident_type' => $role === Role::Resident
                ? $residentRules($user->resident_type !== null, [$this->residentTypeRule()])
                : ['prohibited'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** An Administrator cannot lock themselves out. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $this->account()->is($this->user())) {
                return;
            }
            if ($this->has('active') && ! $this->boolean('active')) {
                $validator->errors()->add('active', 'You cannot deactivate your own account.');
            }
            if ($this->filled('role') && $this->input('role') !== $this->account()->role()?->value) {
                $validator->errors()->add('role', 'You cannot change your own role.');
            }
        }];
    }

    private function account(): User
    {
        return $this->route('user');
    }
}
