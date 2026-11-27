<?php

namespace App\Models;

use App\Enums\ComplaintStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Technician made responsible for a Complaint's repair; ends only at reassignment or Closure. */
class Assignment extends Model
{
    protected $table = 'complaint_assignments';

    protected $fillable = ['technician_id', 'assigned_by', 'assigned_at', 'started_at', 'ended_at', 'end_reason', 'override_reason'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** Not yet ended by a reassignment or Closure. */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    /** An Active Task: current, on a Complaint that is Assigned, In Progress or Returned (design §6.2). */
    public function scopeActiveTask(Builder $query): void
    {
        $query->current()->whereHas('complaint', fn (Builder $complaint) => $complaint->whereIn('status', ComplaintStatus::ACTIVE_TASK));
    }
}
