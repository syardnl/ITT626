<?php

namespace App\Models;

use App\Enums\ComplaintStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step in a Complaint's status history; source ERD: ISSUE_STATUS_HISTORY. A null actor is the system. */
class StatusChange extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'complaint_status_history';

    protected $fillable = ['complaint_id', 'changed_by', 'from_status', 'to_status', 'reason'];

    protected function casts(): array
    {
        return [
            'from_status' => ComplaintStatus::class,
            'to_status' => ComplaintStatus::class,
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
