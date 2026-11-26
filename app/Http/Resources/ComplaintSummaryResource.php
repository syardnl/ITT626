<?php

namespace App\Http\Resources;

use App\Models\Complaint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The privacy-safe view of someone else's Complaint, for lists of open Complaints at a Location and Followers:
 * reference, title, Category, status and date only, never the reporter, description or photos.
 * `following` and `mine` describe the viewer, not the Complaint. @mixin Complaint
 */
class ComplaintSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'reference_no' => $this->reference_no,
            'title' => $this->title,
            'category' => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null,
            'status' => $this->status->value,
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'following' => $this->isFollowedBy($viewer),
            'mine' => $this->isReportedBy($viewer),
        ];
    }
}
