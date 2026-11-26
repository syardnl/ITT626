<?php

namespace App\Http\Requests\Complaints;

use App\Enums\AffectedUnits;
use App\Enums\LocationType;
use App\Enums\Urgency;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A Resident's new Complaint. The place is either "My unit" (`my_unit`) or a
 * `location_id`: any active Common Area, or the Resident's own Unit.
 */
class StoreComplaintRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'my_unit' => ['sometimes', 'accepted', 'prohibits:location_id'],
            'location_id' => ['required_without:my_unit', 'integer', Rule::exists('locations', 'id')->where('active', true)->where(
                fn (Builder $location) => $location->where('type', LocationType::CommonArea->value)->orWhere('id', $this->user()->unit_id)
            )],
            'title' => ['required', 'string', 'between:5,150'],
            'description' => ['required', 'string', 'between:10,3000'],
            'category_id' => ['nullable', 'integer', Rule::exists('complaint_categories', 'id')->where('active', true)],
            'urgency' => ['required', Rule::enum(Urgency::class)],
            'safety_risk' => ['required', 'boolean'],
            'affected_units' => ['required', Rule::enum(AffectedUnits::class)],
            'essential_service_down' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->has('my_unit') && ! $this->user()->unit?->active) {
                $validator->errors()->add('my_unit', 'Your Unit is not available for reports. Please contact the management office.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'location_id.required_without' => 'Choose My unit or a Common Area.',
            'location_id.exists' => 'Choose your own Unit or an active Common Area.',
        ];
    }

    /** The validated answers, with "My unit" resolved to the Resident's Unit. */
    public function answers(): array
    {
        $answers = Arr::except($this->validated(), 'my_unit');

        return $this->has('my_unit') ? [...$answers, 'location_id' => $this->user()->unit_id] : $answers;
    }
}
