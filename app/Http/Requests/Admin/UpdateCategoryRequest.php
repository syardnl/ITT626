<?php

namespace App\Http\Requests\Admin;

use App\Enums\Priority;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        /** @var Category $category */
        $category = $this->route('category');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('complaint_categories', 'name')->ignore($category)],
            'default_priority' => ['sometimes', 'required', Rule::enum(Priority::class)],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'Another Category already has this name.'];
    }
}
