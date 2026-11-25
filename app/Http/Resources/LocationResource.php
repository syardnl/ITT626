<?php

namespace App\Http\Resources;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Location */
class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'code' => $this->code,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'importance' => $this->effectiveImportance()->value,
            'is_shared_area' => $this->isSharedArea(),
            'active' => $this->active,
            'children_count' => $this->whenCounted('children'),
        ];
    }
}
