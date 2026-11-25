<?php

namespace App\Http\Requests\Admin;

use App\Enums\LocationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Allowlisted filters for the Administrator's Location list. `parent=root` lists the Property's direct children. */
class LocationSearchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'parent' => ['sometimes', 'string', 'regex:/^(root|[0-9]+)$/'],
            'type' => ['sometimes', Rule::enum(LocationType::class)],
            'active' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
        ];
    }
}
