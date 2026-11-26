<?php

namespace App\Http\Controllers\Api;

use App\Enums\LocationType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Technicians\StoreTechnicianRequest;
use App\Http\Requests\Technicians\TechnicianSearchRequest;
use App\Http\Requests\Technicians\UpdateTechnicianRequest;
use App\Http\Resources\TechnicianResource;
use App\Models\Location;
use App\Models\Skill;
use App\Models\Technician;
use App\Models\User;
use App\Services\Technicians\TechnicianRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Technician profiles, kept by Managers and Administrators. No delete. */
class TechnicianController extends Controller
{
    public function __construct(private readonly TechnicianRegistry $technicians) {}

    /** Paginated by Technician name; filters combine. */
    public function index(TechnicianSearchRequest $request): AnonymousResourceCollection
    {
        $technicians = Technician::query()
            ->with(Technician::DISPLAY_RELATIONS)
            ->when($request->filled('skill'), fn (Builder $query) => $query->whereHas('skills', fn (Builder $skill) => $skill->whereKey($request->integer('skill'))))
            ->when($request->filled('availability'), fn (Builder $query) => $query->where('availability', $request->string('availability')))
            ->when($request->filled('type'), fn (Builder $query) => $query->where('type', $request->string('type')))
            ->when($request->filled('block'), fn (Builder $query) => $query->where('home_block_id', $request->integer('block')))
            ->join('users', 'users.id', '=', 'technicians.user_id')
            ->orderBy('users.name')->orderBy('technicians.id')
            ->select('technicians.*')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return TechnicianResource::collection($technicians);
    }

    /** A Technician may read only their own profile; anyone else's is concealed as 404. */
    public function show(Request $request, Technician $technician): TechnicianResource
    {
        abort_if($request->user()->hasRole(Role::Technician) && ! $technician->isProfileOf($request->user()), 404);

        return new TechnicianResource($technician->load(Technician::DISPLAY_RELATIONS));
    }

    /** The signed-in Technician's own profile. */
    public function mine(Request $request): TechnicianResource
    {
        $technician = Technician::where('user_id', $request->user()->id)->firstOrFail();

        return new TechnicianResource($technician->load(Technician::DISPLAY_RELATIONS));
    }

    /** Choices for the profile form: Technician accounts still without one, active Skills and Blocks. */
    public function formOptions(): JsonResponse
    {
        return response()->json(['data' => [
            'accounts' => User::query()
                ->where('active', true)
                ->whereHas('roles', fn (Builder $role) => $role->where('name', Role::Technician->value))
                ->whereDoesntHave('technician')
                ->orderBy('name')->orderBy('id')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]),
            'skills' => Skill::query()->where('active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Skill $skill) => ['id' => $skill->id, 'name' => $skill->name]),
            'blocks' => Location::query()->where('type', LocationType::Block)->where('active', true)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Location $block) => ['id' => $block->id, 'code' => $block->code, 'name' => $block->name]),
        ]]);
    }

    public function store(StoreTechnicianRequest $request): JsonResponse
    {
        return (new TechnicianResource($this->technicians->create($request->validated())))
            ->response()->setStatusCode(201);
    }

    public function update(UpdateTechnicianRequest $request, Technician $technician): TechnicianResource
    {
        return new TechnicianResource($this->technicians->update($technician, $request->validated()));
    }
}
