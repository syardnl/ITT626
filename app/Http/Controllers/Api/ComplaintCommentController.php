<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Complaints\StoreCommentRequest;
use App\Http\Resources\TimelineEntry;
use Illuminate\Http\JsonResponse;

/** Comments are only ever added: there is no edit or delete. Who may post what: StoreCommentRequest. */
class ComplaintCommentController extends Controller
{
    public function store(StoreCommentRequest $request): JsonResponse
    {
        $comment = $request->complaint()->comments()->create([
            'author_id' => $request->user()->id,
            'visibility' => $request->visibility(),
            'body' => $request->validated('body'),
        ]);

        return response()->json(['data' => TimelineEntry::comment($comment->load('author.roles'))], 201);
    }
}
