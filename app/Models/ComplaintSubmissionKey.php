<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The Idempotency-Key a Resident sent with a Complaint submission, unique per Resident. */
class ComplaintSubmissionKey extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'key', 'complaint_id'];

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }
}
