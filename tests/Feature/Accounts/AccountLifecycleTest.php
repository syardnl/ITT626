<?php

use App\Enums\Role;
use App\Models\User;
use App\Notifications\PasswordLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Notification::fake());

it('deactivation blocks sign-in and ends existing sessions; reactivation restores access', function () {
    ['admin' => $admin] = accountsSetup();
    $mariam = account(Role::Manager, ['email' => 'mariam@example.test', 'password' => 'correct-horse']);

    // Mariam signs in on her own session.
    auth()->guard('web')->logout();
    $this->postJson('/api/v1/auth/login', ['email' => 'mariam@example.test', 'password' => 'correct-horse'])->assertOk();

    // The Administrator deactivates her.
    $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$mariam->id}", ['active' => false])->assertOk()->assertJsonPath('data.active', false);

    // Back on Mariam's session (the test client shares one cookie jar, so point it
    // at her again): her next request is refused, and she cannot sign back in.
    $this->app['auth']->forgetGuards();
    session()->put(auth()->guard('web')->getName(), $mariam->id);
    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['email' => 'mariam@example.test', 'password' => 'correct-horse'])->assertStatus(422);

    $this->actingAs($admin)->patchJson("/api/v1/admin/users/{$mariam->id}", ['active' => true])->assertOk()->assertJsonPath('data.active', true);
    $this->app['auth']->forgetGuards();
    $this->postJson('/api/v1/auth/login', ['email' => 'mariam@example.test', 'password' => 'correct-horse'])->assertOk();
});

it('deletes stored sessions and invalidates "remember me" on deactivation', function () {
    ['admin' => $admin] = accountsSetup();
    $mariam = account(Role::Manager);
    $rememberToken = $mariam->remember_token;
    DB::table('sessions')->insert(['id' => 'mariam-laptop', 'user_id' => $mariam->id, 'payload' => '', 'last_activity' => time()]);
    config(['session.driver' => 'database']);

    $this->patchJson("/api/v1/admin/users/{$mariam->id}", ['active' => false])->assertOk();

    expect(DB::table('sessions')->where('user_id', $mariam->id)->exists())->toBeFalse()
        ->and($mariam->fresh()->remember_token)->not->toBe($rememberToken);
});

it('refuses to reactivate a Resident whose Unit has been deactivated', function () {
    ['unit' => $unit] = accountsSetup();
    $rina = createAccount(residentBody($unit['id']))->json('data');
    $this->patchJson("/api/v1/admin/users/{$rina['id']}", ['active' => false])->assertOk();
    $this->patchJson("/api/v1/admin/locations/{$unit['id']}", ['active' => false])->assertOk();

    $this->patchJson("/api/v1/admin/users/{$rina['id']}", ['active' => true])->assertStatus(409)
        ->assertJsonPath('message', "This Resident's Unit is deactivated. Reactivate the Unit or move the Resident to another Unit first.");
});

it('resends a fresh set-password link while no password has been set, replacing the old one', function () {
    ['unit' => $unit] = accountsSetup();
    $rina = createAccount(residentBody($unit['id']))->json('data');
    $first = inviteTokenFor('rina@example.test');

    $this->postJson("/api/v1/admin/users/{$rina['id']}/resend-invite")->assertStatus(202)
        ->assertJsonPath('message', 'A new set-password link has been sent to rina@example.test.');
    Notification::assertSentTimes(PasswordLink::class, 2);
    $second = inviteTokenFor('rina@example.test');
    expect($second)->not->toBe($first);

    $body = fn (string $token) => ['token' => $token, 'password' => 'a-long-new-password', 'password_confirmation' => 'a-long-new-password'];
    $this->postJson('/api/v1/auth/reset-password', $body($first))->assertStatus(422);
    $this->postJson('/api/v1/auth/reset-password', $body($second))->assertOk();
});

it('rejects a resend once a password has been set, or while the account is deactivated', function () {
    ['unit' => $unit] = accountsSetup();
    $mariam = account(Role::Manager);
    $rina = createAccount(residentBody($unit['id']))->json('data');
    $this->patchJson("/api/v1/admin/users/{$rina['id']}", ['active' => false])->assertOk();

    $this->postJson("/api/v1/admin/users/{$mariam->id}/resend-invite")->assertStatus(409)
        ->assertJsonPath('message', 'This person has already set a password. They can use "Forgot password" instead.');
    $this->postJson("/api/v1/admin/users/{$rina['id']}/resend-invite")->assertStatus(409);
    Notification::assertSentTimes(PasswordLink::class, 1);
});

it('keeps account management to the Administrator', function (Role $role) {
    ['unit' => $unit] = accountsSetup();
    $target = account(Role::Technician);
    $this->actingAs(account($role, $role === Role::Resident ? ['unit_id' => $unit['id'], 'resident_type' => 'owner'] : []));

    $this->getJson('/api/v1/admin/users')->assertForbidden();
    createAccount(residentBody($unit['id']))->assertForbidden();
    $this->patchJson("/api/v1/admin/users/{$target->id}", ['active' => false])->assertForbidden();
    $this->postJson("/api/v1/admin/users/{$target->id}/resend-invite")->assertForbidden();

    expect(User::count())->toBe(3)->and($target->fresh()->active)->toBeTrue();
})->with([Role::Resident, Role::Manager, Role::Technician]);

it('audits every create, update, deactivate, reactivate and resend, with no secrets', function () {
    ['unit' => $unit] = accountsSetup();
    DB::table('audit_logs')->delete(); // keep only the account entries below
    $rina = createAccount(residentBody($unit['id']))->json('data');
    $token = inviteTokenFor('rina@example.test');
    $this->patchJson("/api/v1/admin/users/{$rina['id']}", ['name' => 'Rina Rahman', 'role' => 'technician'])->assertOk();
    $this->patchJson("/api/v1/admin/users/{$rina['id']}", ['active' => false])->assertOk();
    $this->patchJson("/api/v1/admin/users/{$rina['id']}", ['active' => true])->assertOk();
    $this->postJson("/api/v1/admin/users/{$rina['id']}/resend-invite")->assertStatus(202);

    $response = $this->getJson('/api/v1/admin/audit-logs?target_type=user&per_page=50')->assertOk();
    $entries = collect($response->json('data'))->reverse()->values();

    expect($entries->pluck('action')->all())->toBe(['created', 'account.invite_sent', 'updated', 'deactivated', 'reactivated', 'account.invite_sent'])
        ->and($entries[0]['changes'])->toMatchArray([
            'name' => ['from' => null, 'to' => 'Rina Resident'],
            'email' => ['from' => null, 'to' => 'rina@example.test'],
            'role' => ['from' => null, 'to' => 'resident'],
        ])
        ->and($entries[1]['metadata'])->toBe(['resent' => false])
        ->and($entries[2]['changes'])->toEqual([ // any field order
            'name' => ['from' => 'Rina Resident', 'to' => 'Rina Rahman'],
            'unit_id' => ['from' => $unit['id'], 'to' => null],
            'resident_type' => ['from' => 'tenant', 'to' => null],
            'role' => ['from' => 'resident', 'to' => 'technician'],
        ])
        ->and($entries[3]['changes'])->toBe(['active' => ['from' => true, 'to' => false]])
        ->and($entries[5]['metadata'])->toBe(['resent' => true])
        ->and($entries->every(fn ($e) => $e['actor']['email'] === 'adam@example.test'))->toBeTrue();

    $raw = $response->getContent();
    expect($raw)->not->toContain($token)->not->toContain(hash('sha256', $token))->not->toContain('remember_token');
});
