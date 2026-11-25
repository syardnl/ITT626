<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests drive the system through the /api/v1 HTTP API and assert on
| responses and on what was persisted. They set up state with factories and
| seeders, and call a service directly only when no endpoint exists yet to
| trigger the behaviour (say so in the test). Requests carry the SPA's Origin
| so Sanctum treats them as first-party session-cookie requests, as a browser would.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed(ReferenceSeeder::class);
        $this->withHeader('Origin', config('app.url'));
    })
    ->in('Feature');

function account(Role $role, array $attributes = []): User
{
    return User::factory()->withRole($role)->create($attributes);
}
