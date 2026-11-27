<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One attempt to resolve a Complaint. Only a Resident Return starts a new one (ADR 0005). */
class RepairCycle extends Model
{
    protected $fillable = ['number', 'started_at'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'started_at' => 'datetime'];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }
}
