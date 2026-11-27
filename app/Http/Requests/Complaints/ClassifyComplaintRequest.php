<?php

namespace App\Http\Requests\Complaints;

use App\Enums\ComplaintStatus;
use App\Enums\Priority;
use App\Models\Complaint;
use App\Services\Complaints\ComplaintWorkflow;
use App\Services\Complaints\WorkflowConflict;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A Manager confirming or changing a Complaint's Category and final Priority. Changing to a
 * Priority other than the suggested one (the Priority Score's) needs a reason. The answer reads: not found, then
 * a status that does not allow it (409), and only then a validation error. ComplaintWorkflow
 * checks the status again under the row lock.
 */
class ClassifyComplaintRequest extends FormRequest
{
    private ?Complaint $complaint = null;

    public function authorize(): bool
    {
        $status = $this->complaint()->status;
        if (! in_array($status, ComplaintStatus::CLASSIFIABLE, true)) {
            throw new WorkflowConflict(ComplaintWorkflow::classificationRefusal($status));
        }

        return true;
    }

    public function rules(): array
    {
        return [
            // An active Category, or the one it already has, so a since-retired Category can still be confirmed.
            'category_id' => ['required', 'integer', Rule::exists('complaint_categories', 'id')->where(
                fn (Builder $category) => $category->where('active', true)->orWhere('id', $this->complaint()->category_id),
            )],
            'priority' => ['required', Rule::enum(Priority::class)],
            'priority_reason' => ['nullable', 'string', 'max:1000'],
            'version' => ['required', 'integer'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            // initial_priority is fixed at submission, so reading it outside the workflow's lock is safe.
            // Keeping the current Priority needs no new reason: an override was explained when it was made.
            $suggested = $this->complaint()->initial_priority;
            if ($validator->errors()->has('priority') || $this->priority() === $suggested || $this->priority() === $this->complaint()->priority) {
                return;
            }
            if (blank($this->input('priority_reason'))) {
                $validator->errors()->add('priority_reason', "Give a reason for choosing a Priority other than the suggested {$suggested->label()}.");
            }
        }];
    }

    /** The Complaint being classified, or 404 when the Manager cannot see it. */
    public function complaint(): Complaint
    {
        return $this->complaint ??= Complaint::query()->visibleTo($this->user())->findOrFail((int) $this->route('complaint'));
    }

    public function priority(): Priority
    {
        return Priority::from($this->input('priority'));
    }

    public function reason(): ?string
    {
        $reason = trim((string) $this->validated('priority_reason'));

        return $reason === '' ? null : $reason;
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }
}
