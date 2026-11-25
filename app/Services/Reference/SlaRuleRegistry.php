<?php

namespace App\Services\Reference;

use App\Enums\AuditAction;
use App\Models\SlaRule;
use App\Services\Audit\AuditTrail;
use Illuminate\Support\Facades\DB;

/** Edits SLA Targets: every real change bumps the rule version and is audited. */
class SlaRuleRegistry
{
    public function __construct(private readonly AuditTrail $audit) {}

    /** @param  array{response_minutes?: int, resolution_minutes?: int}  $attributes */
    public function update(SlaRule $rule, array $attributes): SlaRule
    {
        return DB::transaction(function () use ($rule, $attributes) {
            // Lock the row so two edits at once cannot both claim the same next version.
            $rule = SlaRule::query()->lockForUpdate()->findOrFail($rule->getKey());

            $rule->fill($attributes);
            if ($rule->isDirty()) {
                $rule->version++;
                $rule->save();
                $this->audit->recordChange(AuditAction::Updated, $rule);
            }

            return $rule;
        });
    }
}
