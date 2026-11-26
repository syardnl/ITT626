<?php

namespace App\Http\Resources;

use App\Models\Complaint;
use App\Models\StatusChange;
use Illuminate\Http\Request;

/** A Complaint as its reporter opens it: the answers, the Priority Score and the timeline. @mixin Complaint */
class ComplaintDetailResource extends ComplaintResource
{
    public const RELATIONS = ['location', 'category', 'statusHistory.actor'];

    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'urgency' => $this->urgency->value,
            'affected_units' => $this->affected_units->value,
            'essential_service_down' => $this->essential_service_down,
            'priority_score' => $this->priority_score,
            'priority_breakdown' => $this->priority_breakdown,
            'priority_rule_version' => $this->priority_rule_version,
            'initial_priority' => $this->initial_priority->value,
            'timeline' => $this->statusHistory->map(fn (StatusChange $change) => [
                'type' => 'status',
                'from_status' => $change->from_status?->value,
                'to_status' => $change->to_status->value,
                'reason' => $change->reason,
                'actor' => $change->actor ? ['id' => $change->actor->id, 'name' => $change->actor->name] : null,
                'at' => $change->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ])->all(),
        ];
    }
}
