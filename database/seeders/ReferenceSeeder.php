<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Property;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Reference data every environment needs. Safe to re-run; creates no accounts
 * and therefore no passwords.
 */
class ReferenceSeeder extends Seeder
{
    public function run(): void
    {
        if (! Property::query()->exists()) {
            Property::create(['name' => 'Apartment Development', 'timezone' => 'Asia/Kuala_Lumpur']);
        }

        foreach (Role::cases() as $role) {
            RoleModel::firstOrCreate(['name' => $role->value]);
        }
    }
}
