<?php

namespace App\Http\Requests\Complaints;

use App\Enums\ComplaintStatus;
use App\Enums\EvidenceType;
use App\Models\Complaint;
use App\Services\Complaints\ComplaintWorkflow;
use App\Services\Complaints\CompletionDetails;
use App\Services\Complaints\EvidencePhotos;
use App\Services\Complaints\WorkflowConflict;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The assigned Technician completing work (multipart). The answer reads: not found (not theirs),
 * forbidden (no longer the current Assignment), a status that does not allow it (409), then a
 * validation error. A retry with an Idempotency-Key that already succeeded skips the status check.
 */
class CompleteWorkRequest extends FormRequest
{
    /** Tag sent for each photo => the Evidence type it is stored as. */
    private const TAGS = ['before' => EvidenceType::BeforePhoto, 'after' => EvidenceType::AfterPhoto];

    protected function prepareForValidation(): void
    {
        if ($this->hasHeader('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    public function authorize(ComplaintWorkflow $workflow): bool
    {
        $complaint = Complaint::query()->visibleTo($this->user())->findOrFail((int) $this->route('complaint'));
        $key = $this->input('idempotency_key');
        if (is_string($key) && $workflow->findCompletedWith($this->user(), $complaint->id, $key)) {
            return true;   // a retry of a completion that succeeded: answered with the Complaint as it is now
        }
        if (! $complaint->isAssignedTo($this->user())) {
            return false;
        }
        if ($complaint->status !== ComplaintStatus::InProgress) {
            throw new WorkflowConflict(ComplaintWorkflow::completionRefusal($complaint->status));
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['sometimes', 'filled', 'string', 'regex:/^[A-Za-z0-9_-]{16,100}$/'],
            'repair_result' => ['required', 'string', 'max:2000'],
            'parts_used' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'expenses' => ['sometimes', 'array', 'max:20'],
            'expenses.*.description' => ['required', 'string', 'max:200'],
            'expenses.*.quantity' => ['required', 'decimal:0,2', 'min:0.01', 'max:999999.99'],
            'expenses.*.amount' => ['required', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'photos' => ['required', 'list', 'max:5'],
            'photos.*' => ['file', 'max:10240', 'mimetypes:'.implode(',', EvidencePhotos::ACCEPTED_MIME)],
            'photo_tags' => ['required', 'list'],
            'photo_tags.*' => [Rule::in(array_keys(self::TAGS))],
            'version' => ['required', 'integer'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['photos', 'photos.*', 'photo_tags', 'photo_tags.*'])) {
                return;
            }
            $tags = (array) $this->input('photo_tags', []);
            if (count($tags) !== count((array) $this->file('photos', []))) {
                $validator->errors()->add('photo_tags', 'Tag each photo as Before or After.');
            } elseif (! in_array('after', $tags, true)) {
                $validator->errors()->add('photos', 'Add at least one After photo of the finished repair.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'repair_result.required' => 'Describe what you repaired.',
            'photos.required' => 'Add at least one After photo of the finished repair.',
            'photos.max' => 'Attach at most five photos.',
            'photos.*.uploaded' => 'Photo :position could not be received. Each photo must be 10 MB or smaller.',
            'photos.*.max' => 'Each photo must be 10 MB or smaller.',
            'photos.*.mimetypes' => 'Each photo must be a JPEG, PNG or WebP image.',
            'photo_tags.*.in' => 'Tag each photo as Before or After.',
            'expenses.*.quantity.decimal' => 'Give the quantity with at most two decimals.',
            'expenses.*.amount.decimal' => 'Give the amount in ringgit with at most two decimals, e.g. 12.50.',
            'idempotency_key.regex' => 'The Idempotency-Key header must be 16–100 letters, digits, hyphens or underscores.',
        ];
    }

    public function details(): CompletionDetails
    {
        $tags = $this->validated('photo_tags');

        return new CompletionDetails(
            repairResult: trim($this->validated('repair_result')),
            partsUsed: $this->text('parts_used'),
            notes: $this->text('notes'),
            expenses: array_map(fn (array $line) => [
                'description' => trim($line['description']),
                'quantity' => (string) $line['quantity'],
                'amount' => (string) $line['amount'],
            ], $this->validated('expenses', [])),
            photos: array_map(fn ($file, string $tag) => ['file' => $file, 'type' => self::TAGS[$tag]], $this->file('photos'), $tags),
            key: $this->validated('idempotency_key'),
        );
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }

    private function text(string $field): ?string
    {
        $value = trim((string) $this->validated($field));

        return $value === '' ? null : $value;
    }
}
