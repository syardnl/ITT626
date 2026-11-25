<?php

namespace App\Services\Locations;

use App\Models\Location;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The active Location tree as nested arrays, from the Property's children down.
 * A Location shows only if it and all its ancestors are active.
 */
class LocationTree
{
    /** @return list<array<string, mixed>> */
    public function active(?string $search = null): array
    {
        $byParent = Location::query()->active()->orderBy('code')->get()->groupBy(fn (Location $l) => $l->parent_id ?? 0);
        $term = $search === null || trim($search) === '' ? null : Str::lower(trim($search));

        return $this->branch($byParent, 0, $term);
    }

    /** @param  Collection<int|string, Collection<int, Location>>  $byParent */
    private function branch(Collection $byParent, int $parentId, ?string $term): array
    {
        $nodes = [];

        foreach ($byParent->get($parentId, collect()) as $location) {
            $children = $this->branch($byParent, $location->id, $term);

            if ($term !== null && $children === [] && ! $this->matches($location, $term)) {
                continue;
            }

            $nodes[] = [
                'id' => $location->id,
                'type' => $location->type->value,
                'code' => $location->code,
                'name' => $location->name,
                'importance' => $location->effectiveImportance()->value,
                'is_shared_area' => $location->isSharedArea(),
                'children' => $children,
            ];
        }

        return $nodes;
    }

    private function matches(Location $location, string $term): bool
    {
        return Str::contains(Str::lower($location->name), $term) || Str::contains(Str::lower($location->code), $term);
    }
}
