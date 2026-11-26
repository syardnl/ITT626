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
        // A malformed type (e.g. an array) is left for the type rule to reject.
        $sentType = $this->input('type', $technician->type->value);
        $type = is_string($sentType) ? TechnicianType::tryFrom($sentType) : null;

        return [
            ...$this->profileRules('sometimes', $technician->skills()->pluck('skills.id')->all()),
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
