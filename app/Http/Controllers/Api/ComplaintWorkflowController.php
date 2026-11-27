<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Complaints\AssignTechnicianRequest;
use App\Http\Requests\Complaints\ClassifyComplaintRequest;
use App\Http\Requests\Complaints\RecommendTechniciansRequest;
use App\Http\Requests\Complaints\ReviewComplaintRequest;
use App\Http\Resources\ComplaintDetailResource;
use App\Http\Resources\RecommendationEntry;
use App\Models\Category;
use App\Models\Complaint;
use App\Services\Complaints\Candidate;
use App\Services\Complaints\ComplaintWorkflow;
use App\Services\Complaints\TechnicianRecommendations;
use Illuminate\Http\JsonResponse;

/** The workflow actions on a Complaint; each answers with the Complaint as the actor now sees it. Rules in ComplaintWorkflow. */
class ComplaintWorkflowController extends Controller
{
    public function __construct(private readonly ComplaintWorkflow $workflow) {}

    public function review(ReviewComplaintRequest $request, int $complaint): ComplaintDetailResource
    {
        return $this->detail($this->workflow->beginReview($request->user(), $complaint, $request->version()));
    }

    /** Also answers `warnings`: the assigned Technician has no Skill for the new Category (a warning, never a refusal). */
    public function classify(ClassifyComplaintRequest $request, int $complaint): ComplaintDetailResource
    {
        $classified = $this->workflow->classify(
            $request->user(), $complaint, Category::findOrFail($request->validated('category_id')),
            $request->priority(), $request->reason(), $request->version(),
        );
        $technician = $classified->currentAssignment?->technician;

        return $this->detail($classified)->additional(['warnings' => $technician && ! $technician->hasSkillFor($classified->category)
            ? ["{$technician->user->name} has no {$classified->category->name} Skill. Reassign the Complaint if they cannot do this work."]
            : []]);
    }

    public function recommendations(RecommendTechniciansRequest $request, TechnicianRecommendations $recommendations): JsonResponse
    {
        $complaint = $request->complaint();
        $ranked = $recommendations->for($complaint);

        return response()->json(['data' => array_map(
            fn (Candidate $candidate, int $index) => RecommendationEntry::of($candidate, $index + 1, $complaint->category),
            $ranked, array_keys($ranked),
        )]);
    }

    public function assign(AssignTechnicianRequest $request, int $complaint): ComplaintDetailResource
    {
        return $this->detail($this->workflow->assign($request->user(), $complaint, $request->details(), $request->version()));
    }

    private function detail(Complaint $complaint): ComplaintDetailResource
    {
        return new ComplaintDetailResource($complaint->fresh(ComplaintDetailResource::RELATIONS));
    }
}
