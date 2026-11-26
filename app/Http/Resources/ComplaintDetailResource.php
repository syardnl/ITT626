<?php

namespace App\Http\Resources;

use App\Models\Complaint;
use App\Models\Evidence;
use App\Models\StatusChange;
use Illuminate\Http\Request;

/**
 * A Complaint opened in full: the answers, the Priority Score, the photos and the timeline;
 * the office (Manager, Administrator) also sees who reported it. @mixin Complaint
 */
class ComplaintDetailResource extends ComplaintResource
{
    public const RELATIONS = ['location', 'category', 'statusHistory.actor', 'reporter.unit', 'evidence.uploader'];

    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'reporter' => $this->when($request->user()->isOffice(), fn () => [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
                'email' => $this->reporter->email,
                'unit' => $this->reporter->unit ? ['id' => $this->reporter->unit->id, 'code' => $this->reporter->unit->code] : null,
            ]),
            'description' => $this->description,
            'urgency' => $this->urgency->value,
            'affected_units' => $this->affected_units->value,
            'essential_service_down' => $this->essential_service_down,
            'priority_score' => $this->priority_score,
            'priority_breakdown' => $this->priority_breakdown,
            'priority_rule_version' => $this->priority_rule_version,
            'initial_priority' => $this->initial_priority->value,
            'evidence' => $this->evidence->map(fn (Evidence $photo) => [
                'id' => $photo->id,
                'type' => $photo->type->value,
                'mime' => $photo->mime,
                'size_bytes' => $photo->size_bytes,
                'width' => $photo->width,
                'height' => $photo->height,
                'uploaded_by' => ['id' => $photo->uploader->id, 'name' => $photo->uploader->name],
                'url' => "/api/v1/attachments/{$photo->id}",
            ])->all(),
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
