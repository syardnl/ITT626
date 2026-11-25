<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The single way to write the audit log. Actor and IP address default to the
 * current request; any field named like a password, token or secret is redacted.
 */
class AuditTrail
{
    public const REDACTED = '[redacted]';

    /** @param  array<string, mixed>  $changes  field => new value, or field => ['from' => …, 'to' => …] */
    public function record(
        AuditAction $action,
        ?Model $target = null,
        array $changes = [],
        array $metadata = [],
        ?User $actor = null,
    ): AuditLog {
        return AuditLog::create([
            'actor_id' => ($actor ?? auth()->user())?->getKey(),
            'action' => $action,
            'target_type' => $target ? self::typeOf($target) : null,
            'target_id' => $target?->getKey(),
            'changes' => $changes === [] ? null : $this->redact($changes),
            'metadata' => $metadata === [] ? null : $this->redact($metadata),
            'ip_address' => request()?->ip(),
        ]);
    }

    /**
     * Record what just happened to a saved model: on Created every attribute, otherwise
     * only the fields that the last save changed, each as ['from' => …, 'to' => …].
     */
    public function recordChange(AuditAction $action, Model $target, array $metadata = []): AuditLog
    {
        $ignored = [$target->getKeyName(), $target->getCreatedAtColumn(), $target->getUpdatedAtColumn()];

        $after = $action === AuditAction::Created ? $target->getAttributes() : $target->getChanges();
        $before = $action === AuditAction::Created ? [] : $target->getPrevious();

        $changes = [];
        foreach (array_diff_key($after, array_flip($ignored)) as $field => $value) {
            $changes[$field] = ['from' => $before[$field] ?? null, 'to' => $value];
        }

        return $this->record($action, $target, $changes, $metadata);
    }

    /** Short name used as target_type, e.g. App\Models\Location => "location". */
    public static function typeOf(Model $model): string
    {
        return Str::snake(class_basename($model));
    }

    private function redact(array $fields): array
    {
        foreach ($fields as $name => $value) {
            $fields[$name] = match (true) {
                self::isSecret((string) $name) => self::REDACTED,
                is_array($value) => $this->redact($value),
                default => $value,
            };
        }

        return $fields;
    }

    private static function isSecret(string $field): bool
    {
        return (bool) preg_match('/password|token|secret/i', $field);
    }
}
