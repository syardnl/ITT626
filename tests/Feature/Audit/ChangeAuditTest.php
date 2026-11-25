<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Property;
use App\Notifications\PasswordLink;
use App\Services\Audit\AuditTrail;
use Illuminate\Support\Facades\Notification;

it('records a password reset as a change to the account, with the password redacted', function () {
    Notification::fake();
    $rina = account(Role::Resident, ['email' => 'rina@example.test']);
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'rina@example.test']);
    $token = null;
    Notification::assertSentTo($rina, PasswordLink::class, function (PasswordLink $link) use (&$token) {
        parse_str(parse_url($link->url(), PHP_URL_FRAGMENT), $q);

        return (bool) ($token = $q['token']);
    });

    $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])->assertOk();

    $entry = $this->actingAs(account(Role::Administrator))->getJson('/api/v1/admin/audit-logs?action=auth.password_set')
        ->assertJsonCount(1, 'data')->json('data.0');
    expect($entry)
        ->actor->id->toBe($rina->id)
        ->target_type->toBe('user')
        ->target_id->toBe($rina->id)
        ->changes->toBe(['password' => '[redacted]'])
        ->and(json_encode($entry))->not->toContain('new-secret-123')->not->toContain($token);
});

// Called directly: the endpoints that create and edit records arrive with #5–#8,
// which will record through this same AuditTrail::recordChange().
it('records who changed which fields of a record, from what to what', function () {
    $administrator = account(Role::Administrator, ['name' => 'Adam Administrator']);
    $this->actingAs($administrator);
    $block = Location::create(['property_id' => Property::current()->id, 'type' => 'block', 'code' => 'A', 'name' => 'Block A']);
    app(AuditTrail::class)->recordChange(AuditAction::Created, $block);

    $block->update(['name' => 'Block Anggerik']);
    app(AuditTrail::class)->recordChange(AuditAction::Updated, $block);

    $this->getJson('/api/v1/admin/audit-logs?target_type=location')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.action', 'updated')
        ->assertJsonPath('data.0.actor.name', 'Adam Administrator')
        ->assertJsonPath('data.0.target_id', $block->id)
        ->assertJsonPath('data.0.changes', ['name' => ['from' => 'Block A', 'to' => 'Block Anggerik']])
        ->assertJsonPath('data.1.action', 'created')
        ->assertJsonPath('data.1.changes.code', ['from' => null, 'to' => 'A'])
        ->assertJsonPath('data.1.changes.name', ['from' => null, 'to' => 'Block A']);
});

// Called directly for the same reason as above.
it('always redacts fields named like passwords or tokens', function () {
    $this->actingAs(account(Role::Administrator));
    $user = account(Role::Manager, ['password' => 'first-secret']);
    $user->forceFill(['password' => 'second-secret', 'remember_token' => 'abc123', 'name' => 'Renamed'])->save();

    app(AuditTrail::class)->recordChange(AuditAction::Updated, $user);

    $entry = $this->getJson('/api/v1/admin/audit-logs?target_type=user&action=updated')->json('data.0');
    expect($entry['changes'])
        ->password->toBe('[redacted]')
        ->remember_token->toBe('[redacted]')
        ->name->toBe(['from' => $user->getPrevious()['name'], 'to' => 'Renamed'])
        ->and(json_encode($entry))->not->toContain('second-secret')->not->toContain('abc123');
});
