<?php

namespace App\Http\Requests\Admin;

use App\Models\SlaRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSlaRuleRequest extends FormRequest
{
    /** A year; anything longer is almost certainly a typo. */
    private const MAX_MINUTES = 525600;

    public function rules(): array
    {
        $minutes = ['sometimes', 'required', 'integer', 'min:1', 'max:'.self::MAX_MINUTES];

        return [
            'priority' => ['prohibited'],
            'version' => ['prohibited'],
            'response_minutes' => $minutes,
            'resolution_minutes' => $minutes,
        ];
    }

    /** Resolution must take longer than Response, comparing the values the rule would end up with. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['response_minutes', 'resolution_minutes'])) {
                return;
            }

            /** @var SlaRule $rule */
            $rule = $this->route('slaRule');
            $response = (int) $this->input('response_minutes', $rule->response_minutes);
            $resolution = (int) $this->input('resolution_minutes', $rule->resolution_minutes);

            if ($resolution <= $response) {
                $validator->errors()->add('resolution_minutes', "The resolution target must be longer than the response target ({$response} minutes).");
            }
        }];
    }

    public function messages(): array
    {
        return [
            'priority.prohibited' => 'An SLA Target cannot change its Priority.',
            'version.prohibited' => 'The rule version is set by the system.',
            '*.integer' => 'Enter a whole number of minutes.',
            '*.min' => 'Enter at least 1 minute.',
        ];
    }
}
