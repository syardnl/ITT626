<?php

namespace App\Http\Requests\Complaints;

use App\Models\Complaint;
use App\Services\Complaints\ComplaintWorkflow;
use App\Services\Complaints\WorkflowConflict;

/**
 * For requests about a Technician for a Complaint: the answer reads not found, then a Complaint
 * not ready for one (409: not reviewed, not classified, or past assignment), and only then a
 * validation error. ComplaintWorkflow checks again under the row lock.
 */
trait AssignableComplaint
{
    private ?Complaint $complaint = null;

    public function authorize(): bool
    {
        $refusal = ComplaintWorkflow::assignmentRefusal($this->complaint());
        if ($refusal !== null) {
            throw new WorkflowConflict($refusal);
        }

        return true;
    }

    /** The Complaint, or 404 when the Manager cannot see it. */
    public function complaint(): Complaint
    {
        return $this->complaint ??= Complaint::query()->visibleTo($this->user())->findOrFail((int) $this->route('complaint'));
    }
}
