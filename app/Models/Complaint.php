<?php

namespace App\Models;

use App\Enums\AffectedUnits;
use App\Enums\ComplaintStatus;
use App\Enums\LocationType;
use App\Enums\Priority;
use App\Enums\Role;
use App\Enums\Urgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A problem a Resident reported at a Unit or Common Area; source ERD: ISSUE. */
class Complaint extends Model
{
    protected $fillable = [
        'reference_no', 'reporter_id', 'location_id', 'category_id', 'title', 'description',
        'urgency', 'safety_risk', 'affected_units', 'essential_service_down',
        'priority_score', 'priority_breakdown', 'priority_rule_version', 'initial_priority', 'priority', 'status',
    ];

    /** As the column default, so a just-created Complaint answers with its version too. */
    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return [
            'urgency' => Urgency::class,
            'safety_risk' => 'boolean',
            'affected_units' => AffectedUnits::class,
            'essential_service_down' => 'boolean',
            'priority_breakdown' => 'array',
            'initial_priority' => Priority::class,
            'priority' => Priority::class,
            'status' => ComplaintStatus::class,
            'reviewed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(StatusChange::class)->orderBy('created_at')->orderBy('id');
    }

    public function repairCycles(): HasMany
    {
        return $this->hasMany(RepairCycle::class)->orderBy('number');
    }

    public function classificationChanges(): HasMany
    {
        return $this->hasMany(ClassificationChange::class)->orderBy('created_at')->orderBy('id');
    }

    /** Every Assignment, earliest first; reassignment ends one and starts the next. */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class)->orderBy('assigned_at')->orderBy('id');
    }

    /** The Assignment not yet ended, if any: whose work this Complaint is now. */
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(Assignment::class)->whereNull('ended_at');
    }

    /** A warning when the current assignee has no Skill for the Category (never a refusal: the Manager may reassign). */
    public function skillMismatchWarning(): ?string
    {
        $technician = $this->currentAssignment?->technician;
        if (! $technician || ! $this->category || $technician->hasSkillFor($this->category)) {
            return null;
        }

        return "{$technician->user->name} has no {$this->category->name} Skill. Reassign the Complaint if they cannot do this work.";
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderBy('created_at')->orderBy('id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class)->orderBy('id');
    }

    /** Residents tracking this Complaint instead of submitting a duplicate. */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'complaint_followers')->withPivot('created_at');
    }

    public function isFollowedBy(User $user): bool
    {
        return $this->followed_by_viewer ?? $this->followers()->whereKey($user->id)->exists();
    }

    /** Adds `followed_by_viewer`, so a list can show Follow or Following without a query per row. */
    public function scopeWithFollowedBy(Builder $query, User $viewer): void
    {
        $query->withExists(['followers as followed_by_viewer' => fn (Builder $followers) => $followers->whereKey($viewer->id)]);
    }

    /** Not Resolved or Closed. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', ComplaintStatus::NOT_OPEN);
    }

    /** At a Common Area the viewer shares: see Location::commonAreasSharedWith. Never a Unit. */
    public function scopeAtCommonAreasSharedWith(Builder $query, User $viewer): void
    {
        $query->whereIn('location_id', Location::query()->commonAreasSharedWith($viewer)->select('id'));
    }

    public function scopeFollowedBy(Builder $query, User $follower): void
    {
        $query->whereHas('followers', fn (Builder $followers) => $followers->whereKey($follower->id));
    }

    /**
     * The Complaints this account may open: a Resident their own and those they follow, the
     * office (Manager, Administrator) every one, a Technician those whose latest Assignment is theirs.
     * Every read goes through here, so anything outside it is concealed as not found.
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        match (true) {
            $viewer->hasRole(Role::Resident) => $query->where(fn (Builder $own) => $own
                ->where('reporter_id', $viewer->id)
                // Common Areas only, even if a follower row ever pointed elsewhere: Unit Complaints stay private.
                ->orWhere(fn (Builder $followed) => $followed->followedBy($viewer)->whereIn(
                    'location_id', Location::query()->where('type', LocationType::CommonArea)->select('id'),
                ))),
            $viewer->isOffice() => $query,   // no restriction
            $viewer->hasRole(Role::Technician) && $viewer->technician => $query->latestAssignmentHeldBy($viewer->technician->id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * The Technician's latest Assignment: their current one, or one ended only by Closure. An
     * Assignment ended by reassignment is not the latest, so the work has moved to someone else.
     */
    public function scopeLatestAssignmentHeldBy(Builder $query, int $technicianId): void
    {
        $query->whereExists(fn ($latest) => $latest->from('complaint_assignments as latest')
            ->whereColumn('latest.complaint_id', 'complaints.id')
            ->where('latest.technician_id', $technicianId)
            ->whereRaw('latest.id = (SELECT MAX(a.id) FROM complaint_assignments a WHERE a.complaint_id = complaints.id)'));
    }

    /** The most recent Assignment, from the loaded `assignments`: whose work it is or last was. */
    public function latestAssignment(): ?Assignment
    {
        return $this->assignments->last();
    }

    /** Whether this account is the Technician holding the current Assignment: the one who may act on it. */
    public function isAssignedTo(User $user): bool
    {
        return $user->hasRole(Role::Technician) && $this->currentAssignment?->technician?->isProfileOf($user) === true;
    }

    public function isReportedBy(User $user): bool
    {
        return $this->reporter_id === $user->id;
    }

    /**
     * The reporter, the office and the Technician see everything visibleTo lets them open (a
     * Technician only reaches their own); a Follower sees only the summary.
     */
    public function isSeenInFullBy(User $viewer): bool
    {
        return $this->isReportedBy($viewer) || $viewer->isStaff();
    }
}
