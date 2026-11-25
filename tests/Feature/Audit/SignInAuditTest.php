<?php

use App\Enums\Role;

function auditLogAsAdministrator(array $query = [])
{
    return test()->actingAs(account(Role::Administrator))->getJson('/api/v1/admin/audit-logs?'.http_build_query($query));
}

it('records a successful sign-in with the account, time and IP address', function () {
    $this->travelTo(now()->parse('2026-11-25 02:00:00'));
    $rina = account(Role::Resident, ['name' => 'Rina Resident', 'email' => 'rina@example.test', 'password' => 'correct-horse']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'correct-horse'])->assertOk();

    auditLogAsAdministrator(['action' => 'auth.signed_in'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'auth.signed_in')
        ->assertJsonPath('data.0.actor', ['id' => $rina->id, 'name' => 'Rina Resident', 'email' => 'rina@example.test'])
        ->assertJsonPath('data.0.ip_address', '203.0.113.7')
        ->assertJsonPath('data.0.created_at', '2026-11-25T02:00:00Z');
});

it('records a failed sign-in for a known account with the attempted email and IP, never the password', function () {
    $rina = account(Role::Resident, ['email' => 'rina@example.test', 'password' => 'correct-horse']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'wrong-guess'])->assertStatus(422);

    $entry = auditLogAsAdministrator(['action' => 'auth.sign_in_failed'])->assertOk()->assertJsonCount(1, 'data')->json('data.0');

    expect($entry)
        ->actor->toBeNull()
        ->target_type->toBe('user')
        ->target_id->toBe($rina->id)
        ->metadata->toBe(['email' => 'rina@example.test'])
        ->ip_address->toBe('203.0.113.9')
        ->and(json_encode($entry))->not->toContain('wrong-guess');
});

it('records a failed sign-in for an unknown email without a target', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever'])->assertStatus(422);

    auditLogAsAdministrator(['action' => 'auth.sign_in_failed'])
        ->assertJsonPath('data.0.target_type', null)
        ->assertJsonPath('data.0.target_id', null)
        ->assertJsonPath('data.0.metadata', ['email' => 'nobody@example.test']);
});

it('records sign-in attempts refused by throttling', function () {
    account(Role::Resident, ['email' => 'rina@example.test', 'password' => 'correct-horse']);
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'wrong'.$attempt]);
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'correct-horse'])->assertStatus(429);

    auditLogAsAdministrator(['action' => 'auth.sign_in_failed'])
        ->assertJsonPath('meta.total', 6)
        ->assertJsonPath('data.0.metadata', ['email' => 'rina@example.test', 'reason' => 'throttled']);
});

it('records malformed sign-in attempts, keeping whatever email was given', function (array $body, array $metadata) {
    $this->postJson('/api/v1/auth/login', $body)->assertStatus(422);

    auditLogAsAdministrator(['action' => 'auth.sign_in_failed'])
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.metadata', $metadata);
})->with([
    'no password' => [['email' => 'rina@example.test'], ['email' => 'rina@example.test', 'reason' => 'invalid_request']],
    'no email at all' => [['password' => 'guess'], ['reason' => 'invalid_request']],
]);
