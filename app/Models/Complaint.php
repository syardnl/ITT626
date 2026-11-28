<?php

namespace App\Models;

use App\Enums\AffectedUnits;
use App\Enums\ComplaintStatus;
use App\Enums\LocationType;
use App\Enums\Priority;
use App\Enums\Role;
use App\Enums\SlaState;
use App\Enums\Urgency;
use Carbon\CarbonInterface;
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

    /** The latest Repair Cycle: the one whose SLA clock runs now. */
    public function currentCycle(): HasOne
    {
        return $this->hasOne(RepairCycle::class)->ofMany('number', 'max');
    }

    /**
     * The SLA Indicator for a list or detail page, from the loaded `currentCycle.breaches`; null
     * once the Complaint is Resolved or Closed (its breaches stay on the detail page).
     *
     * @return ?array{state: SlaState, due_at: ?CarbonInterface}
     */
    public function slaIndicator(): ?array
    {
        return $this->status->isOpen() && $this->currentCycle ? $this->currentCycle->indicator(now()) : null;
    }

    /** Open, with its current cycle Breached as RepairCycle::indicator decides: a recorded breach or a deadline passed unmet. */
    public function scopeSlaBreached(Builder $query): void
    {
        $now = now();
        $query->open()->whereHas('currentCycle', fn (Builder $cycle) => $cycle->where(fn (Builder $breached) => $breached
            ->whereHas('breaches')
            ->orWhere(fn (Builder $response) => $response->where('number', 1)->whereNull('responded_at')->where('response_due_at', '<', $now))
            ->orWhere(fn (Builder $resolution) => $resolution->whereNull('resolved_at')->where('resolution_due_at', '<', $now))));
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

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class)->orderBy('attempt');
    }

    /** The reporter's Rating, given when they confirmed the repair. */
    public function rating(): HasOne
    {
        return $this->hasOne(Rating::class);
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
     * office (Manager, Administrator) every one, a Technician those they have ever held an Assignment on
     * (a previous assignee reads them, but only the current one acts: isAssignedTo).
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
            $viewer->hasRole(Role::Technician) && $viewer->technician => $query->everAssignedTo($viewer->technician->id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Every Complaint the Technician has held an Assignment on, current or ended. */
    public function scopeEverAssignedTo(Builder $query, int $technicianId): void
    {
        $query->whereHas('assignments', fn (Builder $assignments) => $assignments->where('technician_id', $technicianId));
    }

    /** Complaints the Technician held an Assignment on before it was reassigned to someone else: their read-only history. */
    public function scopeReassignedAwayFrom(Builder $query, int $technicianId): void
    {
        $query->everAssignedTo($technicianId)->whereNot(fn (Builder $latest) => $latest->latestAssignmentHeldBy($technicianId));
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

    /**
     * The most recent Assignment, from the loaded `assignments`: whose work it is or last was. The
     * highest id, as scopeLatestAssignmentHeldBy picks it, whatever the assigned_at times say.
     */
    public function latestAssignment(): ?Assignment
    {
        return $this->assignments->sortBy('id')->last();
    }

    /**
     * Whether this account is the Technician holding the latest Assignment: their current one, or
     * one ended only by Closure. They see staff detail (Internal Comments) and may comment until Closed.
     */
    public function hasLatestAssignee(User $user): bool
    {
        return $user->hasRole(Role::Technician) && $this->latestAssignment()?->technician?->isProfileOf($user) === true;
    }

    /** Whether this viewer reads Internal Comments here: the office, or any Technician who held an Assignment on it (previous ones read-only). */
    public function showsInternalCommentsTo(User $viewer): bool
    {
        return $viewer->isOffice() || $this->hasEverBeenAssignedTo($viewer);
    }

    /** Whether this account is a Technician who has held any Assignment here, current or ended. */
    public function hasEverBeenAssignedTo(User $user): bool
    {
        return $user->hasRole(Role::Technician) && $user->technician !== null
            && $this->assignments->contains('technician_id', $user->technician->id);
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
