<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Evidence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenceController extends Controller
{
    /**
     * A photo, for those who see its Complaint in full: the reporter, Managers and Administrators.
     * Anyone else, Followers included, gets 404 so photos never leak.
     */
    public function show(Request $request, int $evidence): StreamedResponse
    {
        $viewer = $request->user();
        $photo = Evidence::query()
            ->whereHas('complaint', fn ($complaint) => $complaint->visibleTo($viewer))
            ->with('complaint')
            ->findOrFail($evidence);
        abort_unless($photo->complaint->isSeenInFullBy($viewer), 404);
        abort_unless(Storage::disk(Evidence::DISK)->exists($photo->path), 404);

        return Storage::disk(Evidence::DISK)->response($photo->path, "evidence-{$photo->id}.jpg", [
            'Content-Type' => $photo->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
