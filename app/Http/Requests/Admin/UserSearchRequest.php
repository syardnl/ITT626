<?php

namespace App\Http\Requests\Admin;

use App\Enums\LocationType;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Allowlisted filters for the Administrator's account list. */
class UserSearchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'role' => ['sometimes', Rule::enum(Role::class)],
            'block' => ['sometimes', 'integer', Rule::exists('locations', 'id')->where('type', LocationType::Block->value)],
            'active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
