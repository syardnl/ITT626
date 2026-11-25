<?php

use App\Enums\Role;
use App\Models\User;

it('signs in an active account with the right password and returns the account', function () {
    account(Role::Manager, ['name' => 'Mariam Manager', 'email' => 'mariam@example.test', 'password' => 'correct-horse']);

    $this->postJson('/api/v1/auth/login', ['email' => 'mariam@example.test', 'password' => 'correct-horse'])
        ->assertOk()
        ->assertJsonPath('data.email', 'mariam@example.test')
        ->assertJsonPath('data.name', 'Mariam Manager')
        ->assertJsonPath('data.role', 'manager');

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'mariam@example.test');
});

it('gives the same generic error for an unknown email and for a wrong password', function () {
    account(Role::Resident, ['email' => 'rina@example.test', 'password' => 'correct-horse']);

    $unknownEmail = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'correct-horse']);
    $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'wrong']);

    $unknownEmail->assertStatus(422)->assertExactJson([
        'message' => 'Validation failed',
        'errors' => ['email' => ['These credentials are invalid.']],
    ]);
    expect($wrongPassword->status())->toBe(422)
        ->and($wrongPassword->json())->toBe($unknownEmail->json());

    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('refuses to sign in an inactive account, with the same generic error', function () {
    User::factory()->inactive()->withRole(Role::Technician)
        ->create(['email' => 'tariq@example.test', 'password' => 'correct-horse']);

    $this->postJson('/api/v1/auth/login', ['email' => 'tariq@example.test', 'password' => 'correct-horse'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'These credentials are invalid.');

    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('issues a fresh session on sign-in so a planted session id is useless', function () {
    account(Role::Manager, ['email' => 'mariam@example.test', 'password' => 'correct-horse']);
    $this->getJson('/api/v1/me');
    $before = session()->getId();

    $this->postJson('/api/v1/auth/login', ['email' => 'mariam@example.test', 'password' => 'correct-horse'])
        ->assertOk();

    expect(session()->getId())->not->toBe($before);
});
