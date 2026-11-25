<?php

use App\Enums\Role;

it('ends the session on sign-out so later requests are unauthenticated', function () {
    account(Role::Resident, ['email' => 'rina@example.test', 'password' => 'correct-horse']);
    $this->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'correct-horse'])->assertOk();

    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->getJson('/api/v1/me')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
});

it('rejects sign-out when nobody is signed in', function () {
    $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
});
