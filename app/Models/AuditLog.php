<?php

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One append-only audit record. Write through App\Services\Audit\AuditTrail.
 * Updates and deletes are refused for single records (model events) and in bulk
 * (AuditLogBuilder). If deployed, also grant the app's DB user only SELECT and
 * INSERT on audit_logs.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'action', 'target_type', 'target_id', 'changes', 'metadata', 'ip_address'];

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'changes' => 'array',
            'metadata' => 'array',
        ];
    }

    public function newEloquentBuilder($query): AuditLogBuilder
    {
        return new AuditLogBuilder($query);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit records are append-only and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit records are append-only and cannot be deleted.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
