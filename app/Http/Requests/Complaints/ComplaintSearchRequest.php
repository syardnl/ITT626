<?php

namespace App\Http\Requests\Complaints;

use App\Enums\ComplaintStatus;
use App\Enums\LocationType;
use App\Enums\Priority;
use App\Http\Requests\Concerns\AllowlistedSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Allowlisted filters and sorts for the Complaint list. `category=unclassified` finds Complaints without one. */
class ComplaintSearchRequest extends FormRequest
{
    use AllowlistedSort;

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::enum(ComplaintStatus::class)],
            'priority' => ['sometimes', Rule::enum(Priority::class)],
            'category' => ['sometimes', Rule::when(
                $this->input('category') === 'unclassified',
                ['string'],
                ['integer', Rule::exists('complaint_categories', 'id')],
            )],
            'block' => ['sometimes', 'integer', Rule::exists('locations', 'id')->where('type', LocationType::Block->value)],
            'location' => ['sometimes', 'integer', Rule::exists('locations', 'id')],
            'safety_risk' => ['sometimes', 'boolean'],
            'sort' => $this->sortRule(),
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    /**
     * `priority` sorts by severity: ascending runs Low → Critical. `reference_no` sorts by id:
     * references are handed out in insert order, and as text CMP-2026-10000 would precede CMP-2026-9999.
     */
    protected function sortable(): array
    {
        return [
            'created_at' => 'created_at',
            'priority' => DB::raw(Priority::severitySql('priority')),
            'reference_no' => 'id',
        ];
    }
}
