<?php

namespace App\Http\Requests\Technicians;

use App\Enums\Availability;
use App\Enums\LocationType;
use App\Enums\TechnicianType;
use App\Http\Requests\Concerns\AllowlistedSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Allowlisted filters and sorts for the Technician list. */
class TechnicianSearchRequest extends FormRequest
{
    use AllowlistedSort;

    public function rules(): array
    {
        return [
            'skill' => ['sometimes', 'integer', Rule::exists('skills', 'id')],
            'availability' => ['sometimes', Rule::enum(Availability::class)],
            'type' => ['sometimes', Rule::enum(TechnicianType::class)],
            'block' => ['sometimes', 'integer', Rule::exists('locations', 'id')->where('type', LocationType::Block->value)],
            'sort' => $this->sortRule(),
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    protected function sortable(): array
    {
        return ['name' => 'users.name', 'availability' => 'technicians.availability', 'max_tasks' => 'technicians.max_tasks'];
    }
}
