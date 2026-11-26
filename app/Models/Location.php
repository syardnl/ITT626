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

    /**
     * Everything beneath a Block: its Floors and Common Areas, and the Units and
     * Common Areas on those Floors. The tree is at most two levels deep under a Block.
     */
    public function scopeWithinBlock(Builder $query, int $blockId): void
    {
        $query->where(fn (Builder $within) => $within
            ->where('parent_id', $blockId)
            ->orWhereIn('parent_id', Location::query()->select('id')->where('parent_id', $blockId)->where('type', LocationType::Floor)));
    }

    /**
     * The Common Areas whose existing reports a viewer may see and follow: for a Resident, those in
     * their own Block (at Block or Floor level) and the Shared Areas; for the office, every one.
     */
    public function scopeCommonAreasSharedWith(Builder $query, User $viewer): void
    {
        $query->where('type', LocationType::CommonArea)->when(! $viewer->isOffice(), fn (Builder $shared) => $shared
            ->where(fn (Builder $where) => $where
                ->whereNull('parent_id')
                ->when($viewer->blockId(), fn (Builder $inBlock, int $blockId) => $inBlock->orWhere(fn (Builder $own) => $own->withinBlock($blockId)))));
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
