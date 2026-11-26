<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LocationSearchRequest;
use App\Http\Requests\Admin\StoreLocationRequest;
use App\Http\Requests\Admin\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use App\Services\Locations\LocationRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LocationController extends Controller
{
    public function __construct(private readonly LocationRegistry $locations) {}

    /** Paginated list including inactive Locations, ordered by code unless `sort` says otherwise; browse a level with `parent`. */
    public function index(LocationSearchRequest $request): AnonymousResourceCollection
    {
        $parent = $request->string('parent')->toString();

        $locations = $request->applySort(Location::query())
            ->withCount('children')
            ->when($parent === 'root', fn (Builder $query) => $query->whereNull('parent_id'))
            ->when(ctype_digit($parent), fn (Builder $query) => $query->where('parent_id', (int) $parent))
            ->when($request->filled('type'), fn (Builder $query) => $query->where('type', $request->string('type')))
            ->when($request->has('active'), fn (Builder $query) => $query->where('active', $request->boolean('active')))
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(fn (Builder $match) => $match->where('name', 'like', $term)->orWhere('code', 'like', $term));
            })
            ->orderBy('code')
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return LocationResource::collection($locations);
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        return (new LocationResource($this->locations->create($request->validated())))
            ->response()->setStatusCode(201);
    }

    public function update(UpdateLocationRequest $request, Location $location): LocationResource
    {
        return new LocationResource($this->locations->update($location, $request->validated()));
    }
}
