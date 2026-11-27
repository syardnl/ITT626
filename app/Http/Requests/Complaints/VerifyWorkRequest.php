<?php

namespace App\Http\Requests\Complaints;

use App\Enums\ComplaintStatus;
use App\Enums\VerificationDecision;
use App\Models\Complaint;
use App\Services\Complaints\ComplaintWorkflow;
use App\Services\Complaints\WorkflowConflict;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A Manager approving or returning completed work. The answer reads: not found, then a status
 * other than Pending Verification (409), and only then a validation error. ComplaintWorkflow
 * checks again under the row lock.
 */
class VerifyWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $status = Complaint::query()->visibleTo($this->user())->findOrFail((int) $this->route('complaint'))->status;
        if ($status !== ComplaintStatus::PendingVerification) {
            throw new WorkflowConflict(ComplaintWorkflow::verificationRefusal($status));
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(VerificationDecision::class)],
            'reason' => ['nullable', 'string', 'max:1000', Rule::requiredIf(fn () => $this->input('decision') === VerificationDecision::Return->value)],
            'version' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Give a reason for returning the work to the Technician.'];
    }

    public function decision(): VerificationDecision
    {
        return VerificationDecision::from($this->validated('decision'));
    }

    public function reason(): ?string
    {
        return $this->validated('reason') ?: null;
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }
}
