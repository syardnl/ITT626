<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests exercise the system only through the /api/v1 HTTP API.
| Requests carry the SPA's Origin so Sanctum treats them as first-party
| (stateful, session-cookie) requests, exactly as the browser would.
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
