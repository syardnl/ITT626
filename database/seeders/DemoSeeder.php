<?php

namespace Database\Seeders;

use App\Enums\Availability;
use App\Enums\LocationType;
use App\Enums\ResidentType;
use App\Enums\Role;
use App\Enums\TechnicianType;
use App\Models\Location;
use App\Models\Property;
use App\Models\Skill;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demonstration data only: the sample Location tree, one active account
 * per role, all with the password "demo-password", and a Technician profile.
 * Run with `php artisan db:seed --class=DemoSeeder`.
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

        $this->call(DemoLocationSeeder::class);
        $unit = Location::where('code', 'A-01-01')->firstOrFail();

        $this->account(Role::Resident, 'Rina Resident', ['unit_id' => $unit->id, 'resident_type' => ResidentType::Tenant]);
        $this->account(Role::Manager, 'Mariam Manager');
        $technician = $this->account(Role::Technician, 'Tariq Technician');
        $this->account(Role::Administrator, 'Adam Administrator');

        $profile = Technician::updateOrCreate(['user_id' => $technician->id], [
            'type' => TechnicianType::InHouse,
            'availability' => Availability::Available,
            'max_tasks' => 5,
            'home_block_id' => Location::where('code', 'A')->where('type', LocationType::Block)->firstOrFail()->id,
        ]);
        $profile->skills()->sync(Skill::whereHas('category', fn ($category) => $category->whereIn('key', ['plumbing', 'electrical']))->pluck('id'));
    }

    private function account(Role $role, string $name, array $attributes = []): User
    {
        $user = User::updateOrCreate(
            ['email' => "{$role->value}@demo.test"],
            ['property_id' => Property::current()->id, 'name' => $name, 'password' => self::PASSWORD, 'active' => true, ...$attributes],
        );

        $user->assignRole($role);

        return $user;
    }
}
