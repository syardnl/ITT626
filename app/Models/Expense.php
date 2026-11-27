<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One expense line on a Maintenance Record; `amount` is the line's total in MYR. */
class Expense extends Model
{
    protected $fillable = ['description', 'quantity', 'amount', 'currency'];

    protected $attributes = ['currency' => 'MYR'];

    /** A two-decimal amount string such as "185.50" as whole sen (18550). */
    public static function toSen(string $amount): int
    {
        [$ringgit, $sen] = array_pad(explode('.', $amount, 2), 2, '0');

        return (int) $ringgit * 100 + (int) str_pad(substr($sen, 0, 2), 2, '0');
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }
}
