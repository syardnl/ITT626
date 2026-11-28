<?php

namespace App\Models;

use App\Enums\SlaBreachType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A recorded SLA Breach: one Repair Cycle missed its Response or Resolution target. Never cleared. */
class SlaBreach extends Model
{
    protected function casts(): array
    {
        return ['type' => SlaBreachType::class, 'due_at' => 'datetime', 'detected_at' => 'datetime', 'notified_at' => 'datetime'];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function repairCycle(): BelongsTo
    {
        return $this->belongsTo(RepairCycle::class);
    }
}
