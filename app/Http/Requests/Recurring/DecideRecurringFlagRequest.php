<?php

namespace App\Http\Requests\Recurring;

use Illuminate\Foundation\Http\FormRequest;

/** Acknowledging or dismissing a Recurring Problem flag always needs a reason. */
class DecideRecurringFlagRequest extends FormRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'between:1,1000']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Give a reason for this decision.'];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
