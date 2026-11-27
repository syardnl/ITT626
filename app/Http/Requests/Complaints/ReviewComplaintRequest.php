<?php

namespace App\Http\Requests\Complaints;

use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use App\Services\Complaints\WorkflowConflict;
use Illuminate\Foundation\Http\FormRequest;

/** A Manager beginning review. `version` is the one the Manager loaded, so a stale copy is refused (409). */
class ReviewComplaintRequest extends FormRequest
{
    /**
     * The answer reads: not found, then a status that does not allow it (409), and only then a
     * validation error. ComplaintWorkflow checks the status again under the row lock.
     */
    public function authorize(): bool
    {
        $status = Complaint::query()->visibleTo($this->user())->findOrFail((int) $this->route('complaint'))->status;
        if ($status !== ComplaintStatus::Reported) {
            throw new WorkflowConflict("This Complaint is already {$status->label()}.");
        }

        return true;
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer']];
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }
}
