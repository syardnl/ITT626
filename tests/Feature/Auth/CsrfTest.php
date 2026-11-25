<?php

use App\Enums\Role;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    // Laravel skips CSRF checks while running tests; switch them back on, as in production.
    $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
});

it('rejects a sign-in without a CSRF token', function () {
    account(Role::Resident, ['email' => 'rina@example.test', 'password' => 'correct-horse']);

    $this->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'correct-horse'])
        ->assertStatus(419)
        ->assertJson(['message' => 'CSRF token mismatch.']);

    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('rejects a state-changing request from a signed-in account that carries the wrong CSRF token', function () {
    $this->actingAs(account(Role::Manager));

    $this->withHeader('X-CSRF-TOKEN', 'forged')->postJson('/api/v1/auth/logout')->assertStatus(419);

    $this->getJson('/api/v1/me')->assertOk();
});

it('accepts state-changing requests that carry the session CSRF token', function () {
    account(Role::Resident, ['email' => 'rina@example.test', 'password' => 'correct-horse']);
    $this->getJson('/api/v1/me');
    $token = session()->token();

    $this->withHeader('X-CSRF-TOKEN', $token)
        ->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'correct-horse'])
        ->assertOk();
});
