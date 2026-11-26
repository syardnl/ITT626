<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Complaints\ComplaintSearchRequest;
use App\Http\Requests\Complaints\StoreComplaintRequest;
use App\Http\Resources\ComplaintDetailResource;
use App\Http\Resources\ComplaintResource;
use App\Models\Complaint;
use App\Services\Complaints\ComplaintIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Complaints: Residents submit; everyone sees what Complaint::visibleTo allows. No edit, withdraw or delete. */
class ComplaintController extends Controller
{
    public function __construct(private readonly ComplaintIntake $intake) {}

    /** Newest first, paginated. */
    public function index(ComplaintSearchRequest $request): AnonymousResourceCollection
    {
        $complaints = Complaint::query()
            ->visibleTo($request->user())
            ->with(['location', 'category'])
            ->latest()->latest('id')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ComplaintResource::collection($complaints);
    }

    public function show(Request $request, int $complaint): ComplaintDetailResource
    {
        $complaint = Complaint::query()->visibleTo($request->user())->findOrFail($complaint);

        return new ComplaintDetailResource($complaint->load(ComplaintDetailResource::RELATIONS));
    }

    public function store(StoreComplaintRequest $request): JsonResponse
    {
        $complaint = $this->intake->submit($request->user(), $request->answers());

        return (new ComplaintDetailResource($complaint->load(ComplaintDetailResource::RELATIONS)))
            ->response()->setStatusCode(201);
    }
}
