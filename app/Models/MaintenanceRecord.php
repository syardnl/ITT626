<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Technician's record of one repair attempt; each Complete work makes one, numbered by attempt. */
class MaintenanceRecord extends Model
{
    protected $fillable = [
        'assignment_id', 'technician_id', 'repair_cycle_id', 'attempt', 'repair_result', 'parts_used', 'notes', 'submitted_at', 'submission_key',
    ];

    protected function casts(): array
    {
        return ['attempt' => 'integer', 'submitted_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function repairCycle(): BelongsTo
    {
        return $this->belongsTo(RepairCycle::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class)->orderBy('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Evidence::class)->orderBy('id');
    }

    /** The sum of the expense lines in MYR, as a two-decimal string. */
    public function expenseTotal(): string
    {
        $cents = $this->expenses->sum(fn (Expense $expense) => (int) round((float) $expense->amount * 100));

        return number_format($cents / 100, 2, '.', '');
    }
}
