<?php

namespace App\Models;

use App\Enums\CommentVisibility;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Public or Internal Comment on a Complaint. Never edited or deleted. */
class Comment extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'complaint_comments';

    protected $fillable = ['author_id', 'visibility', 'body'];

    protected function casts(): array
    {
        return ['visibility' => CommentVisibility::class];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Internal Comments are for staff: Managers, Administrators read-only, and the Technician, who
     * only ever opens Complaints assigned to them (Complaint::visibleTo). Followers never reach a timeline.
     */
    public function isVisibleTo(User $viewer): bool
    {
        return $this->visibility === CommentVisibility::Public || $viewer->isOffice() || $viewer->hasRole(Role::Technician);
    }
}
