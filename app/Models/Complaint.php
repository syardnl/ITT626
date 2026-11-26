<?php

namespace App\Models;

use App\Enums\AffectedUnits;
use App\Enums\ComplaintStatus;
use App\Enums\Priority;
use App\Enums\Role;
use App\Enums\Urgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A problem a Resident reported at a Unit or Common Area; source ERD: ISSUE. */
class Complaint extends Model
{
    protected $fillable = [
        'reference_no', 'reporter_id', 'location_id', 'category_id', 'title', 'description',
        'urgency', 'safety_risk', 'affected_units', 'essential_service_down',
        'priority_score', 'priority_breakdown', 'priority_rule_version', 'initial_priority', 'priority', 'status',
    ];

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

    /**
     * The Complaints this account may open: a Resident their own, the office (Manager,
     * Administrator) every one, a Technician none until Assignment exists. Every read goes
     * through here, so anything outside it is concealed as not found.
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        match (true) {
            $viewer->hasRole(Role::Resident) => $query->where('reporter_id', $viewer->id),
            $viewer->isOffice() => $query,   // no restriction
            default => $query->whereRaw('1 = 0'),
        };
    }
}
