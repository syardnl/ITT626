<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogSearchRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class AuditLogController extends Controller
{
    public function index(AuditLogSearchRequest $request): AnonymousResourceCollection
    {
        $timezone = Property::current()->timezone;

        $logs = AuditLog::query()
            ->with('actor')
            ->when($request->filled('actor'), function (Builder $query) use ($request) {
                $term = '%'.$request->string('actor').'%';
                $query->whereHas('actor', fn (Builder $actor) => $actor->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->filled('action'), fn (Builder $query) => $query->where('action', $request->string('action')))
            ->when($request->filled('target_type'), fn (Builder $query) => $query->where('target_type', $request->string('target_type')))
            ->when($request->filled('from'), fn (Builder $query) => $query->where('created_at', '>=', Carbon::parse($request->string('from'), $timezone)->startOfDay()->utc()))
            ->when($request->filled('to'), fn (Builder $query) => $query->where('created_at', '<=', Carbon::parse($request->string('to'), $timezone)->endOfDay()->utc()))
            ->latest('created_at')
            ->latest('id')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return AuditLogResource::collection($logs);
    }
}
