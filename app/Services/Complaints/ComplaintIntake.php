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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Records a Resident's new Complaint as Reported, with its Priority Score, reference, first history row and photos. */
class ComplaintIntake
{
    public function __construct(
        private readonly ReferenceNumbers $references,
        private readonly AuditTrail $audit,
        private readonly EvidencePhotos $photos,
    ) {}

    /**
     * @param  array<string, mixed>  $answers  validated submission fields, location_id resolved
     * @param  array<int, UploadedFile>  $files  report photos, at most five
     *
     * @throws ValidationException when a photo cannot be used; nothing is saved
     */
    public function submit(User $reporter, array $answers, array $files = []): Complaint
    {
        // Image work happens before the transaction, so the reference counter is not locked meanwhile.
        $photos = $this->photos->prepare($files);
        $written = [];

        try {
            return $this->record($reporter, $answers, $photos, $written);
        } catch (Throwable $failure) {
            $this->photos->discard($written);   // the rows rolled back; the files must go too
            throw $failure;
        }
    }

    /**
     * @param  list<PreparedPhoto>  $photos
     * @param  list<string>  $written
     */
    private function record(User $reporter, array $answers, array $photos, array &$written): Complaint
    {
        return DB::transaction(function () use ($reporter, $answers, $photos, &$written) {
            // A retried attempt starts clean: files from the attempt that deadlocked are removed.
            $this->photos->discard($written);
            $written = [];

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
            $this->photos->store($complaint, $reporter, $photos, $written);
            $this->audit->recordChange(AuditAction::Created, $complaint);

            return $complaint;
        }, attempts: 3);   // retried if the database reports a deadlock
    }
}
