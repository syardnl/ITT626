<?php

namespace App\Services\Locations;

use App\Enums\AuditAction;
use App\Models\Location;
use App\Models\Property;
use App\Services\Audit\AuditTrail;
use Illuminate\Support\Facades\DB;

/** Creates and changes Locations, keeping the tree consistent and every change audited. */
class LocationRegistry
{
    public function __construct(private readonly AuditTrail $audit) {}

    /** @param  array{type: string, parent_id: ?int, code: string, name: string, importance?: string}  $attributes */
    public function create(array $attributes): Location
    {
        return DB::transaction(function () use ($attributes) {
            $location = Location::create([...$attributes, 'property_id' => Property::current()->id]);
            $this->audit->recordChange(AuditAction::Created, $location);

            return $location->refresh();
        });
    }

    /**
     * Apply an edit. Toggling `active` deactivates or reactivates the Location
     * (there is no hard delete). Field edits and the (de)activation are saved and
     * audited as separate entries, so each action is labelled for what it did.
     *
     * @throws LocationConflict when the tree would be left inconsistent
     */
    public function update(Location $location, array $attributes): Location
    {
        return DB::transaction(function () use ($location, $attributes) {
            $active = array_key_exists('active', $attributes) ? (bool) $attributes['active'] : $location->active;
            unset($attributes['active']);

            $location->fill($attributes);
            if ($location->isDirty()) {
                $location->save();
                $this->audit->recordChange(AuditAction::Updated, $location);
            }

            // Checked after any move, against the new parent; a refusal rolls the whole edit back.
            if ($active !== $location->active) {
                $active ? $this->ensureCanReactivate($location) : $this->ensureCanDeactivate($location);
                $location->active = $active;
                $location->save();
                $this->audit->recordChange($active ? AuditAction::Reactivated : AuditAction::Deactivated, $location);
            }

            return $location->refresh();
        });
    }

    private function ensureCanDeactivate(Location $location): void
    {
        if ($location->residents()->where('active', true)->exists()) {
            throw new LocationConflict('This Unit still has active Residents. Move or deactivate them before deactivating the Unit.');
        }

        if ($location->children()->active()->exists()) {
            throw new LocationConflict('This Location still contains active Locations. Deactivate those first.');
        }
    }

    private function ensureCanReactivate(Location $location): void
    {
        $parent = $location->parent()->first();

        if ($parent && ! $parent->active) {
            throw new LocationConflict('The Location this one sits under is deactivated. Reactivate it first.');
        }
    }
}
