<?php

namespace App\Http\Requests\Complaints;

use App\Services\Complaints\AssignmentDetails;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A Manager assigning or reassigning a Complaint. Only the shape is checked here; what depends on
 * the Complaint and the Technician at that moment (a reason to reassign, On leave, capacity) is
 * checked by ComplaintWorkflow::assign under the row locks.
 */
class AssignTechnicianRequest extends FormRequest
{
    use AssignableComplaint;

    public function rules(): array
    {
        return [
            'technician_id' => ['required', 'integer', 'exists:technicians,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'override_capacity' => ['sometimes', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
            'version' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return ['technician_id.exists' => 'Choose a Technician from the list.'];
    }

    public function details(): AssignmentDetails
    {
        return new AssignmentDetails(
            technicianId: (int) $this->validated('technician_id'),
            note: $this->text('note'),
            reason: $this->text('reason'),
            overrideCapacity: $this->boolean('override_capacity'),
            overrideReason: $this->text('override_reason'),
        );
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }

    /** Trimmed, or null when blank. */
    private function text(string $field): ?string
    {
        $value = trim((string) $this->validated($field));

        return $value === '' ? null : $value;
    }
}
