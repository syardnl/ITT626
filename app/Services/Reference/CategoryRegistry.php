<?php

namespace App\Services\Reference;

use App\Enums\AuditAction;
use App\Models\Category;
use App\Services\Audit\AuditTrail;
use Illuminate\Support\Facades\DB;

/**
 * Edits Categories. Categories are never created or deleted through the app; the
 * matching Skill follows its Category's name and active state, and every change to
 * either is audited.
 */
class CategoryRegistry
{
    public function __construct(private readonly AuditTrail $audit) {}

    /** @param  array{name?: string, default_priority?: string, active?: bool}  $attributes */
    public function update(Category $category, array $attributes): Category
    {
        return DB::transaction(function () use ($category, $attributes) {
            $active = array_key_exists('active', $attributes) ? (bool) $attributes['active'] : $category->active;
            unset($attributes['active']);

            $category->fill($attributes);
            if ($category->isDirty()) {
                $category->save();
                $this->audit->recordChange(AuditAction::Updated, $category);
            }

            if ($active !== $category->active) {
                $category->active = $active;
                $category->save();
                $this->audit->recordChange($active ? AuditAction::Reactivated : AuditAction::Deactivated, $category);
            }

            $this->syncSkill($category);

            return $category->refresh()->load('skill');
        });
    }

    private function syncSkill(Category $category): void
    {
        $skill = $category->skill()->firstOrCreate([], ['name' => $category->name, 'active' => $category->active]);

        $skill->name = $category->name;
        if ($skill->isDirty()) {
            $skill->save();
            $this->audit->recordChange(AuditAction::Updated, $skill);
        }

        if ($skill->active !== $category->active) {
            $skill->active = $category->active;
            $skill->save();
            $this->audit->recordChange($skill->active ? AuditAction::Reactivated : AuditAction::Deactivated, $skill);
        }
    }
}
