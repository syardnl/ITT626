<?php

namespace App\Services\Complaints;

use App\Enums\AffectedUnits;
use App\Enums\AuditAction;
use App\Enums\ComplaintStatus;
use App\Enums\Urgency;
use App\Models\Complaint;
use App\Models\Location;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use Illuminate\Support\Facades\DB;

/** Records a Resident's new Complaint as Reported, with its Priority Score, reference and first history row. */
class ComplaintIntake
{
    public function __construct(
        private readonly ReferenceNumbers $references,
        private readonly AuditTrail $audit,
    ) {}

    /** @param  array<string, mixed>  $answers  validated submission fields, location_id resolved */
    public function submit(User $reporter, array $answers): Complaint
    {
        return DB::transaction(function () use ($reporter, $answers) {
            $location = Location::findOrFail($answers['location_id']);
            $score = PriorityScore::calculate(
                Urgency::from($answers['urgency']),
                (bool) $answers['safety_risk'],
                AffectedUnits::from($answers['affected_units']),
                $location->effectiveImportance(),
                (bool) $answers['essential_service_down'],
            );

            $complaint = Complaint::create([
                ...$answers,
                'reference_no' => $this->references->next(),
                'reporter_id' => $reporter->id,
                'priority_score' => $score->score,
                'priority_breakdown' => $score->breakdown,
                'priority_rule_version' => PriorityScore::RULE_VERSION,
                'initial_priority' => $score->priority,
                'priority' => $score->priority,
                'status' => ComplaintStatus::Reported,
            ]);

            $complaint->statusHistory()->create(['changed_by' => $reporter->id, 'to_status' => ComplaintStatus::Reported]);
            $this->audit->recordChange(AuditAction::Created, $complaint);

            return $complaint;
        }, attempts: 3);   // retried if the database reports a deadlock
    }
}
