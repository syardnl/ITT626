<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\SlaState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function breaches(): HasMany
    {
        return $this->hasMany(SlaBreach::class)->orderBy('due_at')->orderBy('id');
    }

    /**
     * The SLA Indicator at $now, from the loaded `breaches`: Breached once a breach is recorded or
     * a deadline has passed with its milestone still missing (so it shows before the check runs);
     * Due soon when less than 20% of the next deadline's target time is left; otherwise On track.
     * `due_at` is the next deadline still to meet, or null when none is left.
     *
     * @return array{state: SlaState, due_at: ?CarbonInterface}
     */
    public function indicator(CarbonInterface $now): array
    {
        $pending = array_values(array_filter([
            $this->hasResponse() && $this->responded_at === null ? $this->response_due_at : null,
            $this->resolved_at === null ? $this->resolution_due_at : null,
        ]));
        $next = $pending[0] ?? null;

        $state = match (true) {
            $this->breaches->isNotEmpty() || collect($pending)->contains(fn (CarbonInterface $due) => $due->lt($now)) => SlaState::Breached,
            $next !== null && $now->diffInSeconds($next) < SlaState::DUE_SOON_SHARE * $this->started_at->diffInSeconds($next) => SlaState::DueSoon,
            default => SlaState::OnTrack,
        };

        return ['state' => $state, 'due_at' => $next];
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
