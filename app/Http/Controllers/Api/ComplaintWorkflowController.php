<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Complaints\ClassifyComplaintRequest;
use App\Http\Requests\Complaints\ReviewComplaintRequest;
use App\Http\Resources\ComplaintDetailResource;
use App\Models\Category;
use App\Models\Complaint;
use App\Services\Complaints\ComplaintWorkflow;

/** The workflow actions on a Complaint; each answers with the Complaint as the actor now sees it. Rules in ComplaintWorkflow. */
class ComplaintWorkflowController extends Controller
{
    public function __construct(private readonly ComplaintWorkflow $workflow) {}

    public function review(ReviewComplaintRequest $request, int $complaint): ComplaintDetailResource
    {
        return $this->detail($this->workflow->beginReview($request->user(), $complaint, $request->version()));
    }

    public function classify(ClassifyComplaintRequest $request, int $complaint): ComplaintDetailResource
    {
        return $this->detail($this->workflow->classify(
            $request->user(), $complaint, Category::findOrFail($request->validated('category_id')),
            $request->priority(), $request->reason(), $request->version(),
        ));
    }

    private function detail(Complaint $complaint): ComplaintDetailResource
    {
        return new ComplaintDetailResource($complaint->fresh(ComplaintDetailResource::RELATIONS));
    }
}
