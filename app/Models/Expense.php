<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One expense line on a Maintenance Record; `amount` is the line's total in MYR. */
class Expense extends Model
{
    protected $fillable = ['description', 'quantity', 'amount'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }
}
