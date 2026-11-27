<?php

namespace App\Http\Requests\Complaints;

use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use App\Services\Complaints\WorkflowConflict;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The assigned Technician starting work. The answer reads: not found (a Complaint that is not
 * theirs), then forbidden (theirs, but no longer the current Assignment), then a status that does
 * not allow it (409), and only then a validation error. ComplaintWorkflow checks again under the lock.
 */
class StartWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $complaint = Complaint::query()->visibleTo($this->user())->findOrFail((int) $this->route('complaint'));
        if (! $complaint->isAssignedTo($this->user())) {
            return false;
        }
        if (! in_array($complaint->status, ComplaintStatus::STARTABLE, true)) {
            throw new WorkflowConflict("This Complaint is already {$complaint->status->label()}.");
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
