<?php

namespace App\Http\Requests\Complaints;

use App\Enums\ResidentDecision;
use App\Models\Complaint;
use App\Services\Complaints\ComplaintWorkflow;
use App\Services\Complaints\ResidentAnswer;
use App\Services\Complaints\WorkflowConflict;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The reporter confirming or returning a Resolved Complaint. The answer reads: not found, then
 * forbidden (a Follower), then a status other than Resolved (409: answered already, or closed
 * automatically), and only then a validation error. ComplaintWorkflow checks again under the lock.
 */
class ConfirmResolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $complaint = Complaint::query()->visibleTo($this->user())->findOrFail((int) $this->route('complaint'));
        if (! $complaint->isReportedBy($this->user())) {
            return false;
        }
        if ($refusal = ComplaintWorkflow::answerRefusal($complaint)) {
            throw new WorkflowConflict($refusal);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['reason', 'feedback'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(ResidentDecision::class)],
            // A confirmation keeps a Rating and feedback; a return keeps its reason. The other side's fields are ignored.
            'rating' => ['exclude_unless:decision,confirm', 'nullable', 'integer', 'between:1,5'],
            'feedback' => ['exclude_unless:decision,confirm', 'nullable', 'string', 'max:1000'],
            'reason' => ['exclude_unless:decision,return', 'required', 'string', 'max:1000'],
            'version' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Tell us what is still wrong, so the Technician can look again.',
            'rating.between' => 'Choose a rating from 1 to 5.',
        ];
    }

    public function answer(): ResidentAnswer
    {
        $text = fn (string $field) => ($value = $this->validated($field)) === null || $value === '' ? null : $value;   // never `?:`: "0" is text
        $decision = ResidentDecision::from($this->validated('decision'));

        return new ResidentAnswer(
            $decision,
            score: $this->validated('rating') === null ? null : (int) $this->validated('rating'),
            feedback: $text('feedback'),
            reason: $text('reason'),
        );
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }
}
