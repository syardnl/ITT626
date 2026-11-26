<?php

namespace App\Http\Requests\Admin;

use App\Enums\AuditAction;
use App\Http\Requests\Concerns\AllowlistedSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Allowlisted filters and sorts for the audit log. Dates are calendar days in the Property's timezone. */
class AuditLogSearchRequest extends FormRequest
{
    use AllowlistedSort;

    public function rules(): array
    {
        return [
            'actor' => ['sometimes', 'string', 'max:100'],
            'action' => ['sometimes', Rule::enum(AuditAction::class)],
            'target_type' => ['sometimes', 'string', 'max:50'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => $this->sortRule(),
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    protected function sortable(): array
    {
        return ['created_at' => 'created_at', 'action' => 'action', 'target_type' => 'target_type'];
    }
}
