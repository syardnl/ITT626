<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocationTreeRequest;
use App\Services\Locations\LocationTree;
use Illuminate\Http\JsonResponse;

class LocationTreeController extends Controller
{
    /** The active tree for any signed-in role; `q` keeps matches by name or code and the path to them. */
    public function __invoke(LocationTreeRequest $request, LocationTree $tree): JsonResponse
    {
        return response()->json(['data' => $tree->active($request->input('q'))]);
    }
}
