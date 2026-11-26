<?php

namespace App\Http\Controllers\Api;

use App\Enums\ComplaintStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Complaints\ComplaintSearchRequest;
use App\Http\Requests\Complaints\StoreComplaintRequest;
use App\Http\Resources\ComplaintDetailResource;
use App\Http\Resources\ComplaintResource;
use App\Http\Resources\ComplaintSummaryResource;
use App\Models\Complaint;
use App\Models\Location;
use App\Services\Complaints\ComplaintIntake;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Complaints: Residents submit; everyone sees what Complaint::visibleTo allows. No edit, withdraw or delete. */
class ComplaintController extends Controller
{
    public function __construct(private readonly ComplaintIntake $intake) {}

    /** Newest first unless `sort` says otherwise, paginated; filters combine. */
    public function index(ComplaintSearchRequest $request): AnonymousResourceCollection
    {
        $viewer = $request->user();
        $scope = $request->scope();

        $complaints = $request->applySort(Complaint::query())
            ->visibleTo($viewer)
            ->with(['location', 'category'])
            ->when($scope === 'mine', fn (Builder $query) => $query->where('reporter_id', $viewer->id))
            // A followed Complaint stays in the list until it is Closed.
            ->when($scope === 'following', fn (Builder $query) => $query->followedBy($viewer)
                ->where('status', '!=', ComplaintStatus::Closed)
                ->withFollowedBy($viewer))
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(fn (Builder $match) => $match->where('title', 'like', $term)->orWhere('reference_no', 'like', $term));
            })
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->when($request->filled('priority'), fn (Builder $query) => $query->where('priority', $request->string('priority')))
            ->when($request->filled('category'), fn (Builder $query) => $request->input('category') === 'unclassified'
                ? $query->whereNull('category_id')
                : $query->where('category_id', $request->integer('category')))
            ->when($request->filled('block'), fn (Builder $query) => $query->whereIn('location_id', Location::withinBlock($request->integer('block'))->select('id')))
            ->when($request->filled('location'), fn (Builder $query) => $query->where('location_id', $request->integer('location')))
            ->when($request->has('safety_risk'), fn (Builder $query) => $query->where('safety_risk', $request->boolean('safety_risk')))
            ->latest()->latest('id')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return $scope === 'following'
            ? ComplaintSummaryResource::collection($complaints)
            : ComplaintResource::collection($complaints);
    }

    /** In full for the reporter and the office; a Follower gets the summary only. */
    public function show(Request $request, int $complaint): ComplaintDetailResource|ComplaintSummaryResource
    {
        $complaint = Complaint::query()->visibleTo($request->user())->findOrFail($complaint);

        return $complaint->isSeenInFullBy($request->user())
            ? new ComplaintDetailResource($complaint->load(ComplaintDetailResource::RELATIONS))
            : new ComplaintSummaryResource($complaint->load('category'));
    }

    public function store(StoreComplaintRequest $request): JsonResponse
    {
        $complaint = $this->intake->submit($request->user(), $request->answers());

        return (new ComplaintDetailResource($complaint->load(ComplaintDetailResource::RELATIONS)))
            ->response()->setStatusCode(201);
    }
}
