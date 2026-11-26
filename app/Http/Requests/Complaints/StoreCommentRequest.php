<?php

namespace App\Http\Requests\Complaints;

use App\Enums\CommentVisibility;
use App\Enums\ComplaintStatus;
use App\Enums\Role;
use App\Models\Complaint;
use App\Services\Complaints\CommentRefused;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A comment on a Complaint. The reporter posts Public Comments; a Manager posts Public or
 * Internal ones. A Complaint the poster cannot see is not found; one they may only follow is forbidden.
 */
class StoreCommentRequest extends FormRequest
{
    private ?Complaint $complaint = null;

    /**
     * Checked before the body, so the answer reads: not found, then forbidden, then Closed (409),
     * and only then a validation error.
     */
    public function authorize(): bool
    {
        $complaint = $this->complaint();
        if (! $complaint->isReportedBy($this->user()) && ! $this->fromManager()) {
            return false;
        }
        if ($complaint->status === ComplaintStatus::Closed) {
            throw new CommentRefused('This Complaint is Closed, so it takes no more comments.');
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'between:1,2000'],
            'visibility' => ['sometimes', Rule::enum(CommentVisibility::class), ...($this->fromManager() ? [] : [Rule::in([CommentVisibility::Public->value])])],
        ];
    }

    private function fromManager(): bool
    {
        return $this->user()->hasRole(Role::Manager);
    }

    public function messages(): array
    {
        return ['visibility.in' => 'Only the management office can post Internal Comments.'];
    }

    /** The Complaint being commented on, or 404 when the poster cannot see it. */
    public function complaint(): Complaint
    {
        return $this->complaint ??= Complaint::query()->visibleTo($this->user())->findOrFail((int) $this->route('complaint'));
    }

    public function visibility(): CommentVisibility
    {
        return CommentVisibility::from($this->validated('visibility', CommentVisibility::Public->value));
    }
}
