<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** `php artisan migrate:fresh --seed` — reference data only; see DemoSeeder for local demo accounts. */
    public function run(): void
    {
        $this->call(ReferenceSeeder::class);
    }
}
