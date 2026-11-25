<?php

namespace App\Http\Resources;

use App\Models\SlaRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SlaRule */
class SlaRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'priority' => $this->priority->value,
            'response_minutes' => $this->response_minutes,
            'resolution_minutes' => $this->resolution_minutes,
            'version' => $this->version,
            'updated_at' => $this->updated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
