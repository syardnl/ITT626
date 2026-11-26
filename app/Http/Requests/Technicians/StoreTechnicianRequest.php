<?php

namespace App\Http\Requests\Technicians;

use App\Enums\Role;
use App\Enums\TechnicianType;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class StoreTechnicianRequest extends FormRequest
{
    use TechnicianAttributeRules;

    public function rules(): array
    {
        $required = array_map(fn (array $rules) => ['required', ...$rules], $this->profileRules());
        $required['skill_ids.*'] = $this->profileRules()['skill_ids.*'];

        return [
            ...$required,
            'user_id' => ['required', 'integer', $this->technicianAccount(), Rule::unique('technicians', 'user_id')],
            'company_name' => $this->input('type') === TechnicianType::Contractor->value
                ? ['required', ...$this->companyNameRules()]
                : ['prohibited'],
        ];
    }

    /** The account must hold the Technician role. */
    private function technicianAccount(): Exists
    {
        return Rule::exists('users', 'id')->where(fn (Builder $user) => $user->whereExists(
            fn (Builder $role) => $role->from('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->whereColumn('role_user.user_id', 'users.id')
                ->where('roles.name', Role::Technician->value)
        ));
    }
}
