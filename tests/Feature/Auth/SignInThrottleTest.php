<?php

use App\Enums\Role;

it('throttles an account after five failed sign-ins in a minute, even with the right password', function () {
    account(Role::Resident, ['email' => 'rina@example.test', 'password' => 'correct-horse']);

    foreach (range(1, 5) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$attempt}"])
            ->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'wrong'])
            ->assertStatus(422);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'correct-horse'])
        ->assertStatus(429)
        ->assertJsonStructure(['message']);

    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('throttles an IP address after five failed sign-ins in a minute across different accounts', function () {
    account(Role::Resident, ['email' => 'rina@example.test', 'password' => 'correct-horse']);

    foreach (range(1, 5) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson('/api/v1/auth/login', ['email' => "guess{$attempt}@example.test", 'password' => 'wrong'])
            ->assertStatus(422);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'correct-horse'])
        ->assertStatus(429);
});

it('lets the account sign in again once the minute has passed', function () {
    account(Role::Resident, ['email' => 'rina@example.test', 'password' => 'correct-horse']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'wrong']);
    }

    $this->travel(61)->seconds();

    $this->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'correct-horse'])
        ->assertOk();
});
