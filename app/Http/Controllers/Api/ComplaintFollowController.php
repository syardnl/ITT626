<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Complaints\Following;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Residents follow and unfollow other Residents' Common Area Complaints; rules in Following. */
class ComplaintFollowController extends Controller
{
    public function __construct(private readonly Following $following) {}

    public function store(Request $request, int $complaint): Response
    {
        $this->following->follow($request->user(), $complaint);

        return response()->noContent();
    }

    public function destroy(Request $request, int $complaint): Response
    {
        $this->following->unfollow($request->user(), $complaint);

        return response()->noContent();
    }
}
