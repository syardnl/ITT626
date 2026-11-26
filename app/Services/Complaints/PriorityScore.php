<?php

namespace App\Services\Complaints;

use App\Enums\AffectedUnits;
use App\Enums\LocationImportance;
use App\Enums\Priority;
use App\Enums\Urgency;

/**
 * The rule-based Priority Score, rule version 1 (design §6.1). The Category's
 * default priority is deliberately not an input: it is a baseline for the
 * Manager, never a minimum.
 */
final class PriorityScore
{
    public const RULE_VERSION = 1;

    /** @param  array<string, int>  $breakdown  each input's points */
    private function __construct(
        public readonly int $score,
        public readonly array $breakdown,
        public readonly Priority $priority,
    ) {}

    public static function calculate(
        Urgency $urgency,
        bool $safetyRisk,
        AffectedUnits $affectedUnits,
        LocationImportance $importance,
        bool $essentialServiceDown,
    ): self {
        $breakdown = [
            'urgency' => match ($urgency) {
                Urgency::Low => 0, Urgency::Medium => 15, Urgency::High => 30, Urgency::Critical => 45,
            },
            'safety_risk' => $safetyRisk ? 25 : 0,
            'affected_units' => match ($affectedUnits) {
                AffectedUnits::One => 0, AffectedUnits::Several => 10, AffectedUnits::Many => 20,
            },
            'location_importance' => match ($importance) {
                LocationImportance::Normal => 0, LocationImportance::Important => 10, LocationImportance::Essential => 15,
            },
            'essential_service' => $essentialServiceDown ? 20 : 0,
        ];

        $score = min(100, array_sum($breakdown));
        $priority = match (true) {
            $score >= 75 => Priority::Critical,
            $score >= 50 => Priority::High,
            $score >= 25 => Priority::Medium,
            default => Priority::Low,
        };

        // Safeguard: danger or an Essential Service outage is never ranked below High.
        if (($safetyRisk || $essentialServiceDown) && $priority->rank() > Priority::High->rank()) {
            $priority = Priority::High;
        }

        return new self($score, $breakdown, $priority);
    }
}
