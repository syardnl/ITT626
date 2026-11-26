<?php

namespace App\Http\Resources;

use App\Models\Technician;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Technician */
class TechnicianResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'active' => $this->user->active,
            ],
            'type' => $this->type->value,
            'company_name' => $this->company_name,
            'skills' => $this->skills->map(fn ($skill) => ['id' => $skill->id, 'name' => $skill->name, 'active' => $skill->active])->all(),
            'availability' => $this->availability->value,
            'max_tasks' => $this->max_tasks,
            'home_block' => [
                'id' => $this->homeBlock->id,
                'code' => $this->homeBlock->code,
                'name' => $this->homeBlock->name,
            ],
        ];
    }
}
