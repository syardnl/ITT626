<?php

namespace App\Http\Resources;

use App\Models\Comment;
use App\Models\Complaint;
use App\Models\Evidence;
use App\Models\StatusChange;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * A Complaint opened in full: the answers, the Priority Score, the photos and the timeline of status changes and comments;
 * the office (Manager, Administrator) also sees who reported it. @mixin Complaint
 */
class ComplaintDetailResource extends ComplaintResource
{
    public const RELATIONS = ['location', 'category', 'statusHistory.actor', 'reporter.unit', 'evidence.uploader', 'comments.author.roles'];

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
            'timeline' => $this->timelineFor($request->user()),
        ];
    }

    /**
     * Status changes and the comments this viewer may read, oldest first. Entries at the same
     * second keep the order they were stored in, status changes first.
     */
    private function timelineFor(User $viewer): array
    {
        $statuses = $this->statusHistory->map(fn (StatusChange $change) => [
            'at' => $change->created_at->getTimestamp(), 'kind' => 0, 'id' => $change->id, 'entry' => TimelineEntry::status($change),
        ]);
        $comments = $this->comments->filter(fn (Comment $comment) => $comment->isVisibleTo($viewer))->map(fn (Comment $comment) => [
            'at' => $comment->created_at->getTimestamp(), 'kind' => 1, 'id' => $comment->id, 'entry' => TimelineEntry::comment($comment),
        ]);

        // `kind` breaks a same-second tie: the status change first, then the comment.
        return $statuses->concat($comments)
            ->sortBy([['at', 'asc'], ['kind', 'asc'], ['id', 'asc']])
            ->pluck('entry')
            ->values()
            ->all();
    }
}
