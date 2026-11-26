<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditTrail;

/** Seed an entry at a given time. Written through AuditTrail itself: there is no endpoint that creates entries. */
function entryAt(string $utc, AuditAction $action, ?User $actor = null, ?User $target = null): void
{
    test()->travelTo(now()->parse($utc));
    app(AuditTrail::class)->record($action, $target, actor: $actor);
    test()->travelBack();
}

beforeEach(function () {
    $this->administrator = account(Role::Administrator, ['name' => 'Adam Administrator', 'email' => 'adam@example.test']);
    $this->rina = account(Role::Resident, ['name' => 'Rina Resident', 'email' => 'rina@example.test']);
});

it('lets only the Administrator read the audit log', function (Role $role) {
    $this->actingAs(account($role))->getJson('/api/v1/admin/audit-logs')->assertForbidden();
})->with([Role::Resident, Role::Manager, Role::Technician]);

it('answers 401 to anyone not signed in', function () {
    $this->getJson('/api/v1/admin/audit-logs')->assertUnauthorized();
});

it('offers no way to change or delete entries through the API', function (string $method) {
    entryAt('2026-11-20 01:00:00', AuditAction::SignedIn, $this->rina);
    $id = AuditLog::sole()->id;

    $this->actingAs($this->administrator)->json($method, "/api/v1/admin/audit-logs/{$id}", ['action' => 'created'])
        ->assertNotFound();
    expect(AuditLog::sole()->action)->toBe(AuditAction::SignedIn);
})->with(['PUT', 'PATCH', 'DELETE']);

it('refuses to update or delete an entry even from application code', function () {
    entryAt('2026-11-20 01:00:00', AuditAction::SignedIn, $this->rina);
    $entry = AuditLog::sole();

    expect(fn () => $entry->update(['action' => AuditAction::Created]))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(AuditLog::sole()->action)->toBe(AuditAction::SignedIn);
});

it('lists entries newest first, paginated', function () {
    entryAt('2026-11-20 01:00:00', AuditAction::SignedIn, $this->rina);
    entryAt('2026-11-22 01:00:00', AuditAction::SignInFailed);
    entryAt('2026-11-21 01:00:00', AuditAction::SignedIn, $this->administrator);

    $this->actingAs($this->administrator)->getJson('/api/v1/admin/audit-logs?per_page=2')
        ->assertOk()
        ->assertJsonPath('data.*.created_at', ['2026-11-22T01:00:00Z', '2026-11-21T01:00:00Z'])
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2);

    $this->getJson('/api/v1/admin/audit-logs?per_page=2&page=2')
        ->assertJsonPath('data.*.created_at', ['2026-11-20T01:00:00Z']);
});

it('filters by actor name or email, action and target type', function () {
    entryAt('2026-11-20 01:00:00', AuditAction::SignedIn, $this->rina);
    entryAt('2026-11-20 02:00:00', AuditAction::SignedIn, $this->administrator);
    entryAt('2026-11-20 03:00:00', AuditAction::SignInFailed, target: $this->rina);

    $this->actingAs($this->administrator);
    $this->getJson('/api/v1/admin/audit-logs?actor=rina@')->assertJsonPath('data.*.actor.name', ['Rina Resident']);
    $this->getJson('/api/v1/admin/audit-logs?actor=Adam')->assertJsonPath('data.*.actor.name', ['Adam Administrator']);
    $this->getJson('/api/v1/admin/audit-logs?action=auth.sign_in_failed')->assertJsonPath('data.*.created_at', ['2026-11-20T03:00:00Z']);
    $this->getJson('/api/v1/admin/audit-logs?target_type=user')->assertJsonPath('data.*.created_at', ['2026-11-20T03:00:00Z']);
});

it('filters by date range in Malaysian calendar days', function () {
    // 2026-11-20 23:30 UTC is already 21 November in Kuala Lumpur (UTC+8).
    entryAt('2026-11-20 15:59:00', AuditAction::SignedIn, $this->rina); // 20 Nov 23:59 MYT
    entryAt('2026-11-20 16:00:00', AuditAction::SignedIn, $this->rina); // 21 Nov 00:00 MYT
    entryAt('2026-11-21 15:59:59', AuditAction::SignedIn, $this->rina); // 21 Nov 23:59 MYT
    entryAt('2026-11-21 16:00:00', AuditAction::SignedIn, $this->rina); // 22 Nov 00:00 MYT

    $this->actingAs($this->administrator)->getJson('/api/v1/admin/audit-logs?from=2026-11-21&to=2026-11-21')
        ->assertJsonPath('data.*.created_at', ['2026-11-21T15:59:59Z', '2026-11-20T16:00:00Z']);
});

it('rejects filters it does not recognise', function (array $query, string $field) {
    $this->actingAs($this->administrator)->getJson('/api/v1/admin/audit-logs?'.http_build_query($query))
        ->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'unknown action' => [['action' => 'drop_tables'], 'action'],
    'bad date' => [['from' => '21/11/2026'], 'from'],
    'range backwards' => [['from' => '2026-11-22', 'to' => '2026-11-21'], 'to'],
    'page too large' => [['per_page' => 500], 'per_page'],
]);

it('refuses bulk updates and deletes of entries from application code', function () {
    entryAt('2026-11-20 01:00:00', AuditAction::SignedIn, $this->rina);

    expect(fn () => AuditLog::query()->update(['action' => 'created']))->toThrow(LogicException::class)
        ->and(fn () => AuditLog::query()->delete())->toThrow(LogicException::class)
        ->and(AuditLog::sole()->action)->toBe(AuditAction::SignedIn);
});

it('sorts by an allowlisted field, descending with a leading minus', function () {
    entryAt('2026-11-20 01:00:00', AuditAction::Updated, $this->administrator);
    entryAt('2026-11-22 01:00:00', AuditAction::SignedIn, $this->rina);
    entryAt('2026-11-21 01:00:00', AuditAction::Created, $this->administrator);
    $actions = fn (string $sort) => $this->getJson("/api/v1/admin/audit-logs?sort={$sort}")->assertOk()->json('data.*.action');

    $this->actingAs($this->administrator);
    expect($actions('created_at'))->toBe(['updated', 'created', 'auth.signed_in'])
        ->and($actions('action'))->toBe(['auth.signed_in', 'created', 'updated'])
        ->and($actions('-action'))->toBe(['updated', 'created', 'auth.signed_in']);

    $this->getJson('/api/v1/admin/audit-logs?sort=changes')->assertStatus(422)->assertJsonValidationErrors('sort');
});
