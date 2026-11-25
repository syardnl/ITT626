<?php

namespace Database\Seeders;

use App\Enums\ResidentType;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demonstration data only: one active account per role, all with the
 * password "demo-password". Run with `php artisan db:seed --class=DemoSeeder`.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'demo-password';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder creates accounts with known passwords and must not run in production.');
        }

        $this->call(ReferenceSeeder::class);

        $property = Property::current();
        $unit = $this->sampleUnit($property);

        $this->account(Role::Resident, 'Rina Resident', ['unit_id' => $unit->id, 'resident_type' => ResidentType::Tenant]);
        $this->account(Role::Manager, 'Mariam Manager');
        $this->account(Role::Technician, 'Tariq Technician');
        $this->account(Role::Administrator, 'Adam Administrator');
    }

    /** Just enough of the Location tree to give the demo Resident a home. */
    private function sampleUnit(Property $property): Location
    {
        $block = Location::firstOrCreate(['code' => 'A'], ['property_id' => $property->id, 'type' => 'block', 'name' => 'Block A']);
        $floor = Location::firstOrCreate(['code' => 'A-01'], ['property_id' => $property->id, 'parent_id' => $block->id, 'type' => 'floor', 'name' => 'Block A, Floor 1']);

        return Location::firstOrCreate(['code' => 'A-01-01'], ['property_id' => $property->id, 'parent_id' => $floor->id, 'type' => 'unit', 'name' => 'Unit A-01-01']);
    }

    private function account(Role $role, string $name, array $attributes = []): void
    {
        $user = User::updateOrCreate(
            ['email' => "{$role->value}@demo.test"],
            ['property_id' => Property::current()->id, 'name' => $name, 'password' => self::PASSWORD, 'active' => true, ...$attributes],
        );

        $user->assignRole($role);
    }
}
