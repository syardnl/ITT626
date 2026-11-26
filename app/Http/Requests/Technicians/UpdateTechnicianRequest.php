<?php

namespace App\Http\Requests\Technicians;

use App\Enums\TechnicianType;
use App\Models\Technician;
use Illuminate\Foundation\Http\FormRequest;

/** Partial edit: only the fields sent change. The account a profile belongs to never does. */
class UpdateTechnicianRequest extends FormRequest
{
    use TechnicianAttributeRules;

    public function rules(): array
    {
        /** @var Technician $technician */
        $technician = $this->route('technician');
        $type = TechnicianType::tryFrom((string) $this->input('type', $technician->type->value));

        $rules = array_map(fn (array $rules) => ['sometimes', ...$rules], $this->profileRules());
        $rules['skill_ids.*'] = $this->profileRules()['skill_ids.*'];

        return [
            ...$rules,
            'user_id' => ['prohibited'],
            // A Contractor must end up with a company name: sent now, or already on file.
            'company_name' => match (true) {
                $type !== TechnicianType::Contractor => ['prohibited'],
                $this->has('company_name') || $technician->company_name === null => ['required', ...$this->companyNameRules()],
                default => [],
            },
        ];
    }
}
