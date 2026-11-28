<?php

namespace Database\Seeders;

use App\Enums\AffectedUnits;
use App\Enums\ComplaintStatus;
use App\Enums\EvidenceType;
use App\Enums\ResidentDecision;
use App\Enums\Urgency;
use App\Enums\VerificationDecision;
use App\Models\Complaint;
use App\Models\User;
use App\Services\Assignments\AssignmentDetails;
use App\Services\Complaints\ComplaintIntake;
use App\Services\Complaints\ComplaintWorkflow;
use App\Services\Complaints\CompletionDetails;
use App\Services\Complaints\ResidentAnswer;
use Database\Seeders\Concerns\SubmitsSampleComplaints;
use Illuminate\Database\Seeder;

/**
 * One demo Complaint at each Phase 3 stage: Under Review, In Progress, Pending Verification,
 * Resolved awaiting the Resident's confirmation, and Closed with a Rating. Each is submitted by the
 * demo Resident and moved forward through ComplaintWorkflow, one real action at a time, by the demo
 * Manager and Technician. A re-run finds each Complaint by its Idempotency-Key and carries on from
 * the status it has reached, so nothing is done twice. Called by DemoSeeder after the accounts exist.
 */
class DemoWorkflowSeeder extends Seeder
{
    use SubmitsSampleComplaints;

    /** The order a Complaint moves through on the way to Closed. */
    private const PATH = [
        ComplaintStatus::Reported, ComplaintStatus::UnderReview, ComplaintStatus::Assigned, ComplaintStatus::InProgress,
        ComplaintStatus::PendingVerification, ComplaintStatus::Resolved, ComplaintStatus::Closed,
    ];

    private User $manager;

    private User $technician;

    private User $reporter;

    public function __construct(private readonly ComplaintWorkflow $workflow) {}

    public function run(ComplaintIntake $intake): void
    {
        $this->reporter = User::where('email', 'resident@demo.test')->firstOrFail();
        $this->manager = User::where('email', 'manager@demo.test')->firstOrFail();
        $this->technician = User::where('email', 'technician@demo.test')->firstOrFail();

        $stages = [
            ['demo-stage-under-review', 'A-01-CORRIDOR', 'electrical', ComplaintStatus::UnderReview, [
                'title' => 'Corridor light keeps flickering',
                'description' => 'The ceiling light outside unit A-01-01 flickers all evening and sometimes goes off completely.',
                'urgency' => Urgency::Low, 'safety_risk' => false, 'affected_units' => AffectedUnits::Several, 'essential_service_down' => false,
            ], [234, 179, 8]],
            ['demo-stage-in-progress', 'A-TANK', 'plumbing', ComplaintStatus::InProgress, [
                'title' => 'Roof water tank overflowing',
                'description' => 'Water keeps pouring down from the roof tank overflow pipe onto the walkway. The float valve may be stuck.',
                'urgency' => Urgency::Medium, 'safety_risk' => false, 'affected_units' => AffectedUnits::Many, 'essential_service_down' => false,
            ], [14, 116, 144]],
            ['demo-stage-pending-verification', 'A-LOBBY', 'electrical', ComplaintStatus::PendingVerification, [
                'title' => 'Lobby power socket sparks when used',
                'description' => 'The wall socket next to the lobby notice board sparked when the cleaner plugged in the vacuum.',
                'urgency' => Urgency::High, 'safety_risk' => true, 'affected_units' => AffectedUnits::Many, 'essential_service_down' => false,
            ], [124, 58, 237]],
            ['demo-stage-resolved', 'A-STAIR', 'electrical', ComplaintStatus::Resolved, [
                'title' => 'Stairwell lights out between floors 2 and 3',
                'description' => 'The stairwell is completely dark between floors 2 and 3 at night. Residents use their phone torches.',
                'urgency' => Urgency::Medium, 'safety_risk' => true, 'affected_units' => AffectedUnits::Many, 'essential_service_down' => false,
            ], [71, 85, 105]],
            ['demo-stage-closed', 'CARPARK', 'plumbing', ComplaintStatus::Closed, [
                'title' => 'Burst pipe flooding car park bay 12',
                'description' => 'A pipe along the car park ceiling is leaking heavily above bay 12. The water is pooling across the driveway.',
                'urgency' => Urgency::High, 'safety_risk' => false, 'affected_units' => AffectedUnits::Many, 'essential_service_down' => false,
            ], [190, 18, 60]],
        ];

        foreach ($stages as [$key, $locationCode, $categoryKey, $target, $answers, $colour]) {
            $complaint = $this->submit($intake, $this->reporter, $key, $locationCode, $categoryKey, $answers, $colour);
            $this->advance($complaint, $target, $key, $colour);
        }
    }

    /** Takes the next workflow action until the Complaint reaches $target; one already there is left alone. */
    private function advance(Complaint $complaint, ComplaintStatus $target, string $key, array $colour): void
    {
        $goal = array_search($target, self::PATH, true);

        while (($at = array_search($complaint->status, self::PATH, true)) !== false && $at < $goal) {
            $id = $complaint->id;
            $complaint = match ($complaint->status) {
                ComplaintStatus::Reported => $this->workflow->beginReview($this->manager, $id, null),
                ComplaintStatus::UnderReview => $this->classifyAndAssign($complaint),
                ComplaintStatus::Assigned => $this->workflow->startWork($this->technician, $id, null),
                ComplaintStatus::InProgress => $this->completeWork($complaint, $key, $colour),
                ComplaintStatus::PendingVerification => $this->workflow->verify($this->manager, $id, VerificationDecision::Approve, null, null),
                ComplaintStatus::Resolved => $this->workflow->answerResolution($this->reporter, $id, new ResidentAnswer(
                    ResidentDecision::Confirm, score: 5, feedback: 'Fixed the same day and the bay was cleaned up afterwards. Thank you!',
                ), null),
            };
            $complaint = $complaint->fresh();
        }
    }

    /** The Manager confirms the Category and computed Priority (so no override reason), then assigns the demo Technician. */
    private function classifyAndAssign(Complaint $complaint): Complaint
    {
        $this->workflow->classify($this->manager, $complaint->id, $complaint->category, $complaint->initial_priority, null, null);

        return $this->workflow->assign($this->manager, $complaint->id, new AssignmentDetails(
            $this->technician->technician->id, note: 'Please check with the guardhouse for access before you go up.',
        ), null);
    }

    /** A Maintenance Record with a Before and an After photo and one expense line; its key makes a retry harmless. */
    private function completeWork(Complaint $complaint, string $key, array $colour): Complaint
    {
        $photos = [
            ['file' => $this->samplePhoto("Before: {$complaint->title}", $colour, 'BEFORE'), 'type' => EvidenceType::BeforePhoto],
            ['file' => $this->samplePhoto("After: {$complaint->title}", [22, 163, 74], 'AFTER'), 'type' => EvidenceType::AfterPhoto],
        ];

        try {
            return $this->workflow->completeWork($this->technician, $complaint->id, new CompletionDetails(
                repairResult: 'Found the fault, replaced the worn part and tested it. Working normally now.',
                partsUsed: 'Replacement part, sealing tape',
                notes: 'Recommend checking the rest of this line at the next routine inspection.',
                expenses: [['description' => 'Replacement part', 'quantity' => '1', 'amount' => '45.00']],
                photos: $photos,
                key: "{$key}-completion",
            ), null);
        } finally {
            array_map(fn (array $photo) => @unlink($photo['file']->getRealPath()), $photos);
        }
    }
}
