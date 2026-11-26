<?php

namespace App\Http\Requests\Technicians;

use App\Enums\Availability;
use App\Enums\LocationType;
use App\Enums\TechnicianType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Allowlisted filters for the Technician list. */
class TechnicianSearchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'skill' => ['sometimes', 'integer', Rule::exists('skills', 'id')],
            'availability' => ['sometimes', Rule::enum(Availability::class)],
            'type' => ['sometimes', Rule::enum(TechnicianType::class)],
            'block' => ['sometimes', 'integer', Rule::exists('locations', 'id')->where('type', LocationType::Block->value)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
