<?php

namespace App\Http\Requests\Admin;

use App\Enums\LocationType;
use App\Enums\Role;
use App\Http\Requests\Concerns\AllowlistedSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Allowlisted filters and sorts for the Administrator's account list. */
class UserSearchRequest extends FormRequest
{
    use AllowlistedSort;

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'role' => ['sometimes', Rule::enum(Role::class)],
            'block' => ['sometimes', 'integer', Rule::exists('locations', 'id')->where('type', LocationType::Block->value)],
            'active' => ['sometimes', 'boolean'],
            'sort' => $this->sortRule(),
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    protected function sortable(): array
    {
        return ['name' => 'name', 'email' => 'email', 'created_at' => 'created_at'];
    }
}
