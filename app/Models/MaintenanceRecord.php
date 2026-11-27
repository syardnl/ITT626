<?php

namespace App\Models;

use App\Enums\Role;
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

    /** The sum of the expense lines in MYR, as a two-decimal string; added in whole sen, never as floats. */
    public function expenseTotal(): string
    {
        $sen = $this->expenses->sum(fn (Expense $expense) => Expense::toSen($expense->amount));

        return intdiv($sen, 100).'.'.str_pad((string) ($sen % 100), 2, '0', STR_PAD_LEFT);
    }

    /** verification_outcome once a Manager approves the work (the verification ticket sets it). */
    public const APPROVED = 'approved';

    /**
     * Who may see this record, among those who see its Complaint in full: the office every one, a
     * Technician their own, and the reporter only an approved one (story 32: the repair that
     * resolved it), never one waiting for verification or sent back.
     */
    public function isShownTo(User $viewer): bool
    {
        return match (true) {
            $viewer->isOffice() => true,
            $viewer->hasRole(Role::Technician) => $this->technician->isProfileOf($viewer),
            default => $this->verification_outcome === self::APPROVED,
        };
    }
}
