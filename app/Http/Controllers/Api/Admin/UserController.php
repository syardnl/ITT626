<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UserSearchRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use App\Services\Accounts\AccountRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Account management: list, create, edit, (de)activate and resend set-password links. No delete. */
class UserController extends Controller
{
    public function __construct(private readonly AccountRegistry $accounts) {}

    /** Paginated by name; `block` finds Residents whose Unit is in that Block. */
    public function index(UserSearchRequest $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->with(['roles', 'unit'])
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(fn (Builder $match) => $match->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->filled('role'), fn (Builder $query) => $query->whereHas('roles', fn (Builder $role) => $role->where('name', $request->string('role'))))
            // Units sit under a Floor, which sits under the Block.
            ->when($request->filled('block'), fn (Builder $query) => $query->whereHas('unit.parent', fn (Builder $floor) => $floor->where('parent_id', $request->integer('block'))))
            ->when($request->has('active'), fn (Builder $query) => $query->where('active', $request->boolean('active')))
            ->orderBy('name')->orderBy('id')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return AdminUserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        return (new AdminUserResource($this->accounts->create($request->validated())))
            ->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): AdminUserResource
    {
        return new AdminUserResource($this->accounts->update($user, $request->validated()));
    }

    public function resendInvite(User $user): JsonResponse
    {
        $this->accounts->resendInvite($user);

        return response()->json(['message' => "A new set-password link has been sent to {$user->email}."], 202);
    }
}
