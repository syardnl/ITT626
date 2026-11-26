<?php

namespace App\Http\Requests\Technicians;

use App\Enums\Availability;
use App\Enums\LocationType;
use App\Enums\TechnicianType;
use Illuminate\Validation\Rule;

/** Rules shared by creating and editing a Technician profile. */
trait TechnicianAttributeRules
{
    /** Each field's rules, led by $presence ('required' on create, 'sometimes' on edit); array items need no presence rule. */
    protected function profileRules(string $presence): array
    {
        return collect($this->fieldRules())
            ->map(fn (array $rules, string $field) => str_contains($field, '*') ? $rules : [$presence, ...$rules])
            ->all();
    }

    private function fieldRules(): array
    {
        return [
            'type' => [Rule::enum(TechnicianType::class)],
            'skill_ids' => ['array', 'min:1'],
            'skill_ids.*' => ['integer', 'distinct', Rule::exists('skills', 'id')->where('active', true)],
            'availability' => [Rule::enum(Availability::class)],
            'max_tasks' => ['integer', 'min:1', 'max:100'],
            'home_block_id' => ['integer', Rule::exists('locations', 'id')->where('type', LocationType::Block->value)->where('active', true)],
        ];
    }

    protected function companyNameRules(): array
    {
        return ['string', 'max:150'];
    }

    public function messages(): array
    {
        return [
            'user_id.exists' => 'Choose a Technician account.',
            'user_id.unique' => 'This Technician already has a profile.',
            'company_name.required' => 'A Contractor needs a company name.',
            'company_name.prohibited' => 'Only Contractors have a company name.',
            'skill_ids.required' => 'Choose at least one Skill.',
            'skill_ids.min' => 'Choose at least one Skill.',
            'skill_ids.*.exists' => 'Choose an active Skill.',
            'home_block_id.exists' => 'Choose an active Block.',
        ];
    }
}
