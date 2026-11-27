<?php

namespace App\Http\Resources;

use App\Models\ClassificationChange;
use App\Models\Comment;
use App\Models\StatusChange;

/** One line of a Complaint's timeline: a status change, a classification or a comment, with when it happened in UTC. */
final class TimelineEntry
{
    public static function status(StatusChange $change): array
    {
        return [
            'type' => 'status',
            'id' => $change->id,
            'from_status' => $change->from_status?->value,
            'to_status' => $change->to_status->value,
            'reason' => $change->reason,
            'actor' => $change->actor ? ['id' => $change->actor->id, 'name' => $change->actor->name] : null,
            'at' => $change->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** Office only: the reason may be a staff note. */
    public static function classification(ClassificationChange $change): array
    {
        $category = fn ($category) => $category ? ['id' => $category->id, 'name' => $category->name] : null;

        return [
            'type' => 'classification',
            'id' => $change->id,
            'from_category' => $category($change->fromCategory),
            'to_category' => $category($change->toCategory),
            'from_priority' => $change->from_priority->value,
            'to_priority' => $change->to_priority->value,
            'reason' => $change->reason,
            'actor' => ['id' => $change->actor->id, 'name' => $change->actor->name],
            'at' => $change->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public static function comment(Comment $comment): array
    {
        return [
            'type' => 'comment',
            'id' => $comment->id,
            'visibility' => $comment->visibility->value,
            'body' => $comment->body,
            'author' => ['id' => $comment->author->id, 'name' => $comment->author->name, 'role' => $comment->author->role()?->value],
            'at' => $comment->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
