<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ComplaintSummaryResource;
use App\Models\Complaint;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OpenComplaintsController extends Controller
{
    /**
     * Open Complaints already raised at a Location, newest first, so a Resident can follow one
     * instead of submitting a duplicate. Empty for Units and for Common Areas the viewer does not share.
     */
    public function __invoke(Request $request, int $location): AnonymousResourceCollection
    {
        $location = Location::query()->findOrFail($location);

        $complaints = Complaint::query()
            ->where('location_id', $location->id)
            ->open()
            ->atCommonAreasSharedWith($request->user())
            ->with('category')
            ->withFollowedBy($request->user())
            ->latest()->latest('id')
            ->paginate(25);

        return ComplaintSummaryResource::collection($complaints);
    }
}
