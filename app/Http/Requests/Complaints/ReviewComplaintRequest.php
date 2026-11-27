<?php

namespace App\Http\Requests\Complaints;

use App\Models\Complaint;
use Illuminate\Foundation\Http\FormRequest;

/** A Manager beginning review. `version` is the one the Manager loaded, so a stale copy is refused (409). */
class ReviewComplaintRequest extends FormRequest
{
    /** Not found comes before a validation error. */
    public function authorize(): bool
    {
        Complaint::query()->visibleTo($this->user())->findOrFail((int) $this->route('complaint'));

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
