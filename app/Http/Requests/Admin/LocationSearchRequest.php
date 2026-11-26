<?php

namespace App\Http\Requests\Admin;

use App\Enums\LocationType;
use App\Http\Requests\Concerns\AllowlistedSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Allowlisted filters and sorts for the Administrator's Location list. `parent=root` lists the Property's direct children. */
class LocationSearchRequest extends FormRequest
{
    use AllowlistedSort;

    public function rules(): array
    {
        return [
            'parent' => ['sometimes', 'string', 'regex:/^(root|[0-9]+)$/'],
            'type' => ['sometimes', Rule::enum(LocationType::class)],
            'active' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:100'],
            'sort' => $this->sortRule(),
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
        ];
    }

    protected function sortable(): array
    {
        return ['code' => 'code', 'name' => 'name', 'type' => 'type'];
    }
}
