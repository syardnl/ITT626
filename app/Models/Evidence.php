<?php

namespace App\Models;

use App\Enums\EvidenceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A photo attached to a Complaint, stored re-encoded on the private `evidence` disk. Never deleted. */
class Evidence extends Model
{
    public const DISK = 'evidence';

    public const UPDATED_AT = null;

    protected $table = 'evidence';

    protected $fillable = ['complaint_id', 'maintenance_record_id', 'uploaded_by', 'type', 'path', 'mime', 'size_bytes', 'width', 'height'];

    protected function casts(): array
    {
        return ['type' => EvidenceType::class];
    }

    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }

    /**
     * Among those who see the Complaint in full: report photos go to all of them; Before and After
     * photos to those who may see their Maintenance Record (MaintenanceRecord::isShownTo).
     */
    public function isShownTo(User $viewer): bool
    {
        return $this->maintenance_record_id === null || $this->maintenanceRecord->isShownTo($viewer);
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
