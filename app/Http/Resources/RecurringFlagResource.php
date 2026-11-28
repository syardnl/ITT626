<?php

namespace App\Http\Resources;

use App\Models\Complaint;
use App\Models\RecurringFlag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** A Recurring Problem flag with its evidence and decision. Needs `complaints` set (see withComplaints). @mixin RecurringFlag */
class RecurringFlagResource extends JsonResource
{
    /** @var Collection<int, Complaint> keyed by id */
    private Collection $complaints;

    /** @param  Collection<int, Complaint>  $complaints  keyed by id; those this flag lists are picked out */
    public function withComplaints(Collection $complaints): static
    {
        $this->complaints = $complaints;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $time = fn ($at) => $at?->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'active' => $this->active,
            'location' => ['id' => $this->location->id, 'code' => $this->location->code, 'name' => $this->location->name],
            'category' => ['id' => $this->category->id, 'name' => $this->category->name],
            'complaint_count' => $this->complaint_count,
            'complaints' => collect($this->complaint_ids)->map(fn (int $id) => $this->complaints->get($id))->filter()
                ->sortBy('created_at')->values()->map(fn (Complaint $complaint) => [
                    'id' => $complaint->id,
                    'reference_no' => $complaint->reference_no,
                    'title' => $complaint->title,
                    'status' => $complaint->status->value,
                    'created_at' => $time($complaint->created_at),
                ])->all(),
            'first_reported_at' => $time($this->first_reported_at),
            'last_reported_at' => $time($this->last_reported_at),
            'decided_by' => $this->decider ? ['id' => $this->decider->id, 'name' => $this->decider->name] : null,
            'decided_at' => $time($this->decided_at),
            'reason' => $this->reason,
            'superseded_at' => $time($this->superseded_at),
            'raised_at' => $time($this->created_at),
        ];
    }
}
