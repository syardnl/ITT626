<?php

namespace Database\Seeders;

use App\Enums\LocationImportance;
use App\Enums\LocationType;
use App\Models\Location;
use App\Models\Property;
use Illuminate\Database\Seeder;

/**
 * A sample Location tree for local demonstration. Importance defaults as agreed:
 * lifts, water tanks/pumps, main switch rooms and the guardhouse are essential;
 * lobbies, car parks and stairwells are important; everything else is normal.
 */
class DemoLocationSeeder extends Seeder
{
    private const BLOCKS = ['A' => 'Block A', 'B' => 'Block B'];

    private const FLOORS = 3;

    private const UNITS_PER_FLOOR = 4;

    private const SHARED_AREAS = [
        'GUARDHOUSE' => ['Guardhouse', LocationImportance::Essential],
        'MSR' => ['Main switch room', LocationImportance::Essential],
        'PUMP' => ['Water pump room', LocationImportance::Essential],
        'CARPARK' => ['Car park', LocationImportance::Important],
        'POOL' => ['Swimming pool', LocationImportance::Normal],
        'PLAYGROUND' => ['Playground', LocationImportance::Normal],
    ];

    private int $propertyId;

    public function run(): void
    {
        $this->propertyId = Property::current()->id;

        foreach (self::SHARED_AREAS as $code => [$name, $importance]) {
            $this->place(LocationType::CommonArea, null, $code, $name, $importance);
        }

        foreach (self::BLOCKS as $blockCode => $blockName) {
            $block = $this->place(LocationType::Block, null, $blockCode, $blockName);

            $this->place(LocationType::CommonArea, $block, "{$blockCode}-LIFT-1", "{$blockName} lift 1", LocationImportance::Essential);
            $this->place(LocationType::CommonArea, $block, "{$blockCode}-LIFT-2", "{$blockName} lift 2", LocationImportance::Essential);
            $this->place(LocationType::CommonArea, $block, "{$blockCode}-TANK", "{$blockName} roof water tank", LocationImportance::Essential);
            $this->place(LocationType::CommonArea, $block, "{$blockCode}-LOBBY", "{$blockName} lobby", LocationImportance::Important);
            $this->place(LocationType::CommonArea, $block, "{$blockCode}-STAIR", "{$blockName} stairwell", LocationImportance::Important);

            foreach (range(1, self::FLOORS) as $level) {
                $floorCode = sprintf('%s-%02d', $blockCode, $level);
                $floor = $this->place(LocationType::Floor, $block, $floorCode, "{$blockName}, Floor {$level}");
                $this->place(LocationType::CommonArea, $floor, "{$floorCode}-CORRIDOR", "{$blockName}, Floor {$level} corridor");

                foreach (range(1, self::UNITS_PER_FLOOR) as $number) {
                    $unitCode = sprintf('%s-%02d', $floorCode, $number);
                    $this->place(LocationType::Unit, $floor, $unitCode, "Unit {$unitCode}");
                }
            }
        }
    }

    private function place(LocationType $type, ?Location $parent, string $code, string $name, LocationImportance $importance = LocationImportance::Normal): Location
    {
        return Location::updateOrCreate(['code' => $code], [
            'property_id' => $this->propertyId,
            'parent_id' => $parent?->id,
            'type' => $type,
            'name' => $name,
            'importance' => $importance,
            'active' => true,
        ]);
    }
}
