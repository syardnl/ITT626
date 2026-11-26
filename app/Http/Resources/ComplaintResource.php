<?php

namespace App\Http\Resources;

use App\Models\Complaint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A Complaint as a list shows it. @mixin Complaint */
class ComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_no' => $this->reference_no,
            'title' => $this->title,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'safety_risk' => $this->safety_risk,
            'category' => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null,
            'location' => [
                'id' => $this->location->id,
                'code' => $this->location->code,
                'name' => $this->location->name,
                'type' => $this->location->type->value,
            ],
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
