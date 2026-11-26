<?php

namespace App\Services\Technicians;

use App\Enums\AuditAction;
use App\Enums\TechnicianType;
use App\Models\Technician;
use App\Services\Audit\AuditTrail;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Creates and edits Technician profiles; every change is audited. */
class TechnicianRegistry
{
    public function __construct(private readonly AuditTrail $audit) {}

    /** @param  array<string, mixed>  $attributes  validated profile fields plus skill_ids */
    public function create(array $attributes): Technician
    {
        return DB::transaction(function () use ($attributes) {
            $technician = Technician::create(Arr::except($attributes, 'skill_ids'));
            $technician->skills()->sync($attributes['skill_ids']);

            $this->audit->recordChange(AuditAction::Created, $technician, alsoChanged: [
                'skill_ids' => ['from' => null, 'to' => $this->skillIds($technician)],
            ]);

            return $this->reloadForDisplay($technician);
        });
    }

    /** @param  array<string, mixed>  $attributes  validated fields to change; skill_ids replaces the whole set */
    public function update(Technician $technician, array $attributes): Technician
    {
        return DB::transaction(function () use ($technician, $attributes) {
            $technician->fill(Arr::except($attributes, 'skill_ids'));
            if ($technician->type === TechnicianType::InHouse) {
                $technician->company_name = null;
            }

            $skillChange = [];
            if (array_key_exists('skill_ids', $attributes)) {
                $before = $this->skillIds($technician);
                $technician->skills()->sync($attributes['skill_ids']);
                $after = $this->skillIds($technician);
                if ($before !== $after) {
                    $skillChange = ['skill_ids' => ['from' => $before, 'to' => $after]];
                }
            }

            if ($technician->isDirty() || $skillChange !== []) {
                $technician->save();
                $this->audit->recordChange(AuditAction::Updated, $technician, alsoChanged: $skillChange);
            }

            return $this->reloadForDisplay($technician);
        });
    }

    private function skillIds(Technician $technician): array
    {
        return $technician->skills()->pluck('skills.id')->sort()->values()->all();
    }

    private function reloadForDisplay(Technician $technician): Technician
    {
        return $technician->refresh()->load(Technician::DISPLAY_RELATIONS);
    }
}
