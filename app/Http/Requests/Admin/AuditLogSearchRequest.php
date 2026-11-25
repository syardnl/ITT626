<?php

namespace App\Http\Requests\Admin;

use App\Enums\AuditAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Allowlisted filters for the audit log. Dates are calendar days in the Property's timezone. */
class AuditLogSearchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'actor' => ['sometimes', 'string', 'max:100'],
            'action' => ['sometimes', Rule::enum(AuditAction::class)],
            'target_type' => ['sometimes', 'string', 'max:50'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
