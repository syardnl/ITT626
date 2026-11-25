<?php

use App\Enums\Role;

/** The SLA Target for a Priority, as the Administrator list returns it. */
function slaRule(string $priority): array
{
    return collect(test()->getJson('/api/v1/admin/sla-rules')->assertOk()->json('data'))->firstWhere('priority', $priority);
}

function patchSlaRule(string $priority, array $changes)
{
    return test()->patchJson('/api/v1/admin/sla-rules/'.slaRule($priority)['id'], $changes);
}

beforeEach(fn () => $this->actingAs(account(Role::Administrator)));

it('lists the four SLA Targets from Critical to Low', function () {
    $this->getJson('/api/v1/admin/sla-rules')->assertOk()
        ->assertJsonPath('data.*.priority', ['critical', 'high', 'medium', 'low'])
        ->assertJsonStructure(['data' => [['id', 'priority', 'response_minutes', 'resolution_minutes', 'version', 'updated_at']]]);
});

it('changes the minutes and increments the rule version on every change', function () {
    patchSlaRule('high', ['response_minutes' => 120])->assertOk()
        ->assertJsonPath('data.response_minutes', 120)
        ->assertJsonPath('data.resolution_minutes', 1440)
        ->assertJsonPath('data.version', 2);

    patchSlaRule('high', ['response_minutes' => 180, 'resolution_minutes' => 2880])->assertOk()
        ->assertJsonPath('data.version', 3);

    expect(slaRule('high'))->toMatchArray(['response_minutes' => 180, 'resolution_minutes' => 2880, 'version' => 3])
        ->and(slaRule('critical')['version'])->toBe(1);
});

it('leaves the version alone when nothing changed', function () {
    patchSlaRule('low', ['response_minutes' => 4320, 'resolution_minutes' => 10080])->assertOk()->assertJsonPath('data.version', 1);

    $this->getJson('/api/v1/admin/audit-logs')->assertJsonCount(0, 'data');
});

it('accepts only positive whole minutes', function (mixed $minutes) {
    patchSlaRule('medium', ['response_minutes' => $minutes])->assertStatus(422)->assertJsonValidationErrors('response_minutes');
    patchSlaRule('medium', ['resolution_minutes' => $minutes])->assertStatus(422)->assertJsonValidationErrors('resolution_minutes');

    expect(slaRule('medium'))->toMatchArray(['response_minutes' => 1440, 'resolution_minutes' => 4320, 'version' => 1]);
})->with([
    'zero' => 0,
    'negative' => -30,
    'fraction' => 90.5,
    'text' => 'an hour',
    'empty' => null,
    'more than a year' => 525601,
]);

it('requires the resolution target to be longer than the response target', function () {
    patchSlaRule('critical', ['response_minutes' => 480])->assertStatus(422)
        ->assertJsonPath('errors.resolution_minutes.0', 'The resolution target must be longer than the response target (480 minutes).');
    patchSlaRule('critical', ['resolution_minutes' => 30])->assertStatus(422)->assertJsonValidationErrors('resolution_minutes');
    patchSlaRule('critical', ['response_minutes' => 600, 'resolution_minutes' => 500])->assertStatus(422)->assertJsonValidationErrors('resolution_minutes');

    patchSlaRule('critical', ['response_minutes' => 479])->assertOk();
});

it('does not let an edit change the Priority or set the version', function () {
    patchSlaRule('low', ['priority' => 'critical'])->assertStatus(422)->assertJsonValidationErrors('priority');
    patchSlaRule('low', ['version' => 99])->assertStatus(422)->assertJsonValidationErrors('version');
});

it('answers 404 for an SLA Target that does not exist', function () {
    $this->patchJson('/api/v1/admin/sla-rules/999999', ['response_minutes' => 10])->assertNotFound();
});

it('keeps the SLA endpoints to the Administrator', function (Role $role) {
    $high = slaRule('high');
    $this->actingAs(account($role));

    $this->getJson('/api/v1/admin/sla-rules')->assertForbidden();
    $this->patchJson("/api/v1/admin/sla-rules/{$high['id']}", ['response_minutes' => 1])->assertForbidden();
})->with([Role::Resident, Role::Manager, Role::Technician]);

it('audits every SLA Target change with the version it moved to', function () {
    $high = slaRule('high');
    patchSlaRule('high', ['response_minutes' => 120])->assertOk();

    $this->getJson('/api/v1/admin/audit-logs')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'updated')
        ->assertJsonPath('data.0.target_type', 'sla_rule')
        ->assertJsonPath('data.0.target_id', $high['id'])
        ->assertJsonPath('data.0.changes', [
            'response_minutes' => ['from' => 240, 'to' => 120],
            'version' => ['from' => 1, 'to' => 2],
        ]);
});
