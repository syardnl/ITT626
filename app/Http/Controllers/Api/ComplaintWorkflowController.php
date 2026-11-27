<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Complaints\AssignTechnicianRequest;
use App\Http\Requests\Complaints\ClassifyComplaintRequest;
use App\Http\Requests\Complaints\CompleteWorkRequest;
use App\Http\Requests\Complaints\RecommendTechniciansRequest;
use App\Http\Requests\Complaints\ReviewComplaintRequest;
use App\Http\Requests\Complaints\StartWorkRequest;
use App\Http\Requests\Complaints\VerifyWorkRequest;
use App\Http\Resources\ComplaintDetailResource;
use App\Http\Resources\RecommendationEntry;
use App\Models\Category;
use App\Models\Complaint;
use App\Services\Assignments\Candidate;
use App\Services\Assignments\TechnicianRecommendations;
use App\Services\Complaints\ComplaintWorkflow;
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

        return $this->detail($classified)->additional(['warnings' => array_values(array_filter([$classified->skillMismatchWarning()]))]);
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

    public function startWork(StartWorkRequest $request, int $complaint): ComplaintDetailResource
    {
        return $this->detail($this->workflow->startWork($request->user(), $complaint, $request->version()));
    }

    public function completeWork(CompleteWorkRequest $request, int $complaint): ComplaintDetailResource
    {
        return $this->detail($this->workflow->completeWork($request->user(), $complaint, $request->details(), $request->version()));
    }

    public function verify(VerifyWorkRequest $request, int $complaint): ComplaintDetailResource
    {
        return $this->detail($this->workflow->verify($request->user(), $complaint, $request->decision(), $request->reason(), $request->version()));
    }

    private function detail(Complaint $complaint): ComplaintDetailResource
    {
        return new ComplaintDetailResource($complaint->fresh(ComplaintDetailResource::RELATIONS));
    }
}
