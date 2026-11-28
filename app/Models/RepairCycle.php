<?php

namespace App\Models;

use App\Enums\Priority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to resolve a Complaint. Only a Resident Return starts a new one (ADR 0005). It keeps
 * the SLA Targets it started with, its deadlines and its milestones (design §6.3); only cycle 1
 * has a Response.
 */
class RepairCycle extends Model
{
    protected $fillable = ['number', 'started_at', 'sla_targets'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'started_at' => 'datetime',
            'sla_targets' => 'array',
            'response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'responded_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function hasResponse(): bool
    {
        return $this->number === 1;
    }

    /**
     * The deadlines at this Priority, counted in calendar minutes from the cycle's start with the
     * Targets it started with (ADR 0004). A Response that has happened keeps its deadline.
     */
    public function applyPriority(Priority $priority): void
    {
        $target = $this->targetFor($priority);
        $this->resolution_due_at = $target ? $this->started_at->copy()->addMinutes($target['resolution_minutes']) : null;
        if ($this->hasResponse() && $this->responded_at === null) {
            $this->response_due_at = $target ? $this->started_at->copy()->addMinutes($target['response_minutes']) : null;
        }
    }

    /** @return ?array{response_minutes: int, resolution_minutes: int, version: int} */
    public function targetFor(Priority $priority): ?array
    {
        return $this->sla_targets[$priority->value] ?? null;
    }
}
