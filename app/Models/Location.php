<?php

namespace App\Models;

use App\Enums\LocationImportance;
use App\Enums\LocationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A node of the Property's tree: Block, Floor, Unit or Common Area. A null parent means the Property. */
class Location extends Model
{
    protected $fillable = ['property_id', 'parent_id', 'type', 'code', 'name', 'importance', 'active'];

    protected $attributes = ['importance' => 'normal', 'active' => true];

    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'importance' => LocationImportance::class,
            'active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_id');
    }

    public function residents(): HasMany
    {
        return $this->hasMany(User::class, 'unit_id');
    }

    /** Importance as everyone should read it: only Common Areas carry one; everything else is normal. */
    public function effectiveImportance(): LocationImportance
    {
        return $this->type === LocationType::CommonArea ? $this->importance : LocationImportance::Normal;
    }

    /** A Common Area attached directly to the Property; scored as its own group. */
    public function isSharedArea(): bool
    {
        return $this->type === LocationType::CommonArea && $this->parent_id === null;
    }

    /** True when $candidate is this Location or sits anywhere beneath it. */
    public function isSelfOrAncestorOf(Location $candidate): bool
    {
        for ($node = $candidate; $node; $node = $node->parent) {
            if ($node->is($this)) {
                return true;
            }
        }

        return false;
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
