<?php

use App\Enums\Role;

/** The seeded Category with this name, as the Administrator list returns it. */
function adminCategory(string $name): array
{
    return collect(test()->getJson('/api/v1/admin/categories')->assertOk()->json('data'))->firstWhere('name', $name);
}

function patchCategory(array $category, array $changes)
{
    return test()->patchJson("/api/v1/admin/categories/{$category['id']}", $changes);
}

beforeEach(fn () => $this->actingAs(account(Role::Administrator)));

it('lists every Category with its Skill for the Administrator', function () {
    $this->getJson('/api/v1/admin/categories')->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('data.0.name', 'Common Facilities')
        ->assertJsonPath('data.0.default_priority', 'low')
        ->assertJsonPath('data.0.skill.name', 'Common Facilities');
});

it('renames a Category and its Skill with it', function () {
    $lift = adminCategory('Lift');

    patchCategory($lift, ['name' => 'Lifts & Escalators'])->assertOk()
        ->assertJsonPath('data.name', 'Lifts & Escalators')
        ->assertJsonPath('data.skill.name', 'Lifts & Escalators');

    expect(collect($this->getJson('/api/v1/admin/skills')->json('data'))->pluck('name'))
        ->toContain('Lifts & Escalators')->not->toContain('Lift');
});

it('keeps Category names unique', function () {
    patchCategory(adminCategory('Lift'), ['name' => 'Electrical'])
        ->assertStatus(422)->assertJsonPath('errors.name.0', 'Another Category already has this name.');
    patchCategory(adminCategory('Lift'), ['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
});

it('changes a Category default priority, accepting only a known Priority', function () {
    $lift = adminCategory('Lift');

    patchCategory($lift, ['default_priority' => 'critical'])->assertOk()->assertJsonPath('data.default_priority', 'critical');
    patchCategory($lift, ['default_priority' => 'urgent'])->assertStatus(422)->assertJsonValidationErrors('default_priority');
});

it('deactivates and reactivates a Category together with its Skill', function () {
    $lift = adminCategory('Lift');

    patchCategory($lift, ['active' => false])->assertOk()
        ->assertJsonPath('data.active', false)->assertJsonPath('data.skill.active', false);
    expect(collect($this->getJson('/api/v1/categories')->json('data'))->pluck('name'))->not->toContain('Lift');

    patchCategory($lift, ['active' => true])->assertOk()
        ->assertJsonPath('data.active', true)->assertJsonPath('data.skill.active', true);
    expect(collect($this->getJson('/api/v1/categories')->json('data'))->pluck('name'))->toContain('Lift');
});

it('has no way to create or delete a Category or a Skill', function () {
    $lift = adminCategory('Lift');

    $this->postJson('/api/v1/admin/categories', ['name' => 'Gardening', 'default_priority' => 'low'])->assertStatus(405);
    $this->deleteJson("/api/v1/admin/categories/{$lift['id']}")->assertStatus(405);
    $this->postJson('/api/v1/admin/skills', ['name' => 'Gardening'])->assertStatus(405);
    $this->getJson('/api/v1/admin/categories')->assertJsonCount(10, 'data');
});

it('answers 404 for a Category that does not exist', function () {
    $this->patchJson('/api/v1/admin/categories/999999', ['name' => 'Ghost'])->assertNotFound();
});

it('shows any signed-in role the active Categories only', function (Role $role) {
    patchCategory(adminCategory('Lift'), ['active' => false])->assertOk();

    $this->actingAs(account($role))->getJson('/api/v1/categories')->assertOk()
        ->assertJsonCount(9, 'data')
        ->assertJsonMissingPath('data.0.skill')
        ->assertJsonStructure(['data' => [['id', 'name', 'default_priority', 'active']]]);
})->with(Role::cases());

it('lists Skills for the Administrator, one per Category', function () {
    $categories = collect($this->getJson('/api/v1/admin/categories')->json('data'));
    $skills = collect($this->getJson('/api/v1/admin/skills')->assertOk()->json('data'));

    expect($skills)->toHaveCount(10)
        ->and($skills->pluck('category_id')->sort()->values()->all())->toBe($categories->pluck('id')->sort()->values()->all())
        ->and($skills->pluck('name')->sort()->values()->all())->toBe($categories->pluck('name')->sort()->values()->all());
});

it('keeps the admin Category and Skill endpoints to the Administrator', function (Role $role) {
    $lift = adminCategory('Lift');
    $this->actingAs(account($role));

    $this->getJson('/api/v1/admin/categories')->assertForbidden();
    patchCategory($lift, ['name' => 'Hijacked'])->assertForbidden();
    $this->getJson('/api/v1/admin/skills')->assertForbidden();
})->with([Role::Resident, Role::Manager, Role::Technician]);

it('answers 401 to anyone not signed in', function () {
    auth()->guard('web')->logout();

    $this->getJson('/api/v1/categories')->assertUnauthorized();
});

it('audits every Category change, and the Skill changes it causes', function () {
    $lift = adminCategory('Lift');

    patchCategory($lift, ['name' => 'Lifts', 'default_priority' => 'critical'])->assertOk();
    patchCategory($lift, ['active' => false])->assertOk();
    patchCategory($lift, ['active' => true])->assertOk();

    $entries = collect($this->getJson('/api/v1/admin/audit-logs?per_page=50')->json('data'))
        ->map(fn ($e) => [$e['target_type'], $e['action'], $e['changes']])->reverse()->values()->all();

    expect($entries)->toBe([
        ['category', 'updated', ['name' => ['from' => 'Lift', 'to' => 'Lifts'], 'default_priority' => ['from' => 'high', 'to' => 'critical']]],
        ['skill', 'updated', ['name' => ['from' => 'Lift', 'to' => 'Lifts']]],
        ['category', 'deactivated', ['active' => ['from' => true, 'to' => false]]],
        ['skill', 'deactivated', ['active' => ['from' => true, 'to' => false]]],
        ['category', 'reactivated', ['active' => ['from' => false, 'to' => true]]],
        ['skill', 'reactivated', ['active' => ['from' => false, 'to' => true]]],
    ]);
});

it('writes no audit entry when nothing changed', function () {
    $lift = adminCategory('Lift');

    patchCategory($lift, ['name' => 'Lift', 'default_priority' => 'high', 'active' => true])->assertOk();

    $this->getJson('/api/v1/admin/audit-logs')->assertJsonCount(0, 'data');
});
