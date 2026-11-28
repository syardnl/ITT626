<?php

namespace App\Models;

use App\Enums\RecurringFlagStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Recurring Problem flag: three or more Complaints at one exact Location and Category within a
 * rolling 90 days (design §6.4). The current flag of a group has no superseded_at; a dismissed one
 * is superseded by a new open flag only when a new Complaint joins the group.
 */
class RecurringFlag extends Model
{
    /** Complaints in the window that raise a flag. */
    public const THRESHOLD = 3;

    public const WINDOW_DAYS = 90;

    protected $fillable = ['location_id', 'category_id', 'status', 'active', 'complaint_ids', 'complaint_count', 'first_reported_at', 'last_reported_at'];

    protected function casts(): array
    {
        return [
            'status' => RecurringFlagStatus::class,
            'active' => 'boolean',
            'complaint_ids' => 'array',
            'complaint_count' => 'integer',
            'first_reported_at' => 'datetime',
            'last_reported_at' => 'datetime',
            'decided_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The one flag per group that detection keeps up to date. */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('superseded_at');
    }

    /** Counts toward the Health Score: current, not dismissed, and the group still at three or more. */
    public function scopeActiveProblem(Builder $query): void
    {
        $query->current()->where('active', true)->where('status', '!=', RecurringFlagStatus::Dismissed);
    }
}
