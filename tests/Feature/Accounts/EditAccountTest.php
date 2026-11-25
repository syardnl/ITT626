<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Notification::fake());

function patchAccount(array|User $account, array $changes)
{
    $id = is_array($account) ? $account['id'] : $account->id;

    return test()->patchJson("/api/v1/admin/users/{$id}", $changes);
}

it('edits name, email, Unit and Resident Type', function () {
    ['unit' => $unit, 'other' => $other] = accountsSetup();
    $rina = createAccount(residentBody($unit['id']))->json('data');

    patchAccount($rina, ['name' => 'Rina Rahman', 'email' => 'Rina.Rahman@Example.test', 'unit_id' => $other['id'], 'resident_type' => 'owner'])->assertOk()
        ->assertJsonPath('data.name', 'Rina Rahman')
        ->assertJsonPath('data.email', 'rina.rahman@example.test')
        ->assertJsonPath('data.unit.code', 'A-01-02')
        ->assertJsonPath('data.resident_type', 'owner');
});

it('keeps emails unique when editing', function () {
    ['unit' => $unit] = accountsSetup();
    $rina = createAccount(residentBody($unit['id']))->json('data');

    patchAccount($rina, ['email' => 'ADAM@example.test'])->assertStatus(422)->assertJsonPath('errors.email.0', 'Another account already uses this email.');
    patchAccount($rina, ['email' => 'rina@example.test'])->assertOk();
});

it('changes a role, keeping exactly one, and clears the Unit when a Resident becomes staff', function () {
    ['unit' => $unit] = accountsSetup();
    $rina = createAccount(residentBody($unit['id']))->json('data');

    patchAccount($rina, ['role' => 'technician'])->assertOk()
        ->assertJsonPath('data.role', 'technician')
        ->assertJsonPath('data.unit', null)
        ->assertJsonPath('data.resident_type', null);

    expect(DB::table('role_user')->where('user_id', $rina['id'])->count())->toBe(1);
});

it('requires a Unit and Resident Type when staff becomes a Resident', function () {
    ['unit' => $unit] = accountsSetup();
    $sam = createAccount(['name' => 'Sam', 'email' => 'sam@example.test', 'role' => 'manager'])->json('data');

    patchAccount($sam, ['role' => 'resident'])->assertStatus(422)->assertJsonValidationErrors(['unit_id', 'resident_type']);
    patchAccount($sam, ['role' => 'resident', 'unit_id' => $unit['id'], 'resident_type' => 'student'])->assertOk()
        ->assertJsonPath('data.role', 'resident')->assertJsonPath('data.unit.code', 'A-01-01');
});

it('refuses to give a staff account a Unit, or a Resident an inactive or missing Unit', function () {
    ['unit' => $unit, 'other' => $other, 'floor' => $floor] = accountsSetup();
    $sam = createAccount(['name' => 'Sam', 'email' => 'sam@example.test', 'role' => 'manager'])->json('data');
    $rina = createAccount(residentBody($unit['id']))->json('data');

    patchAccount($sam, ['unit_id' => $unit['id']])->assertStatus(422)->assertJsonPath('errors.unit_id.0', 'Only Resident accounts are linked to a Unit.');
    patchAccount($rina, ['unit_id' => null])->assertStatus(422)->assertJsonValidationErrors('unit_id');
    patchAccount($rina, ['unit_id' => $floor['id']])->assertStatus(422)->assertJsonPath('errors.unit_id.0', 'Choose an active Unit.');

    $this->patchJson("/api/v1/admin/locations/{$other['id']}", ['active' => false])->assertOk();
    patchAccount($rina, ['unit_id' => $other['id']])->assertStatus(422)->assertJsonPath('errors.unit_id.0', 'Choose an active Unit.');
});

it('does not let an Administrator deactivate their own account or change their own role', function () {
    ['admin' => $admin] = accountsSetup();

    patchAccount($admin, ['active' => false])->assertStatus(422)->assertJsonPath('errors.active.0', 'You cannot deactivate your own account.');
    patchAccount($admin, ['role' => 'manager'])->assertStatus(422)->assertJsonPath('errors.role.0', 'You cannot change your own role.');
    patchAccount($admin, ['name' => 'Adam A.', 'role' => 'administrator'])->assertOk();
});

it('answers 404 for an account that does not exist', function () {
    accountsSetup();
    $this->patchJson('/api/v1/admin/users/999999', ['name' => 'Ghost'])->assertNotFound();
});

it('has no way to delete an account', function () {
    ['admin' => $admin] = accountsSetup();
    $this->deleteJson("/api/v1/admin/users/{$admin->id}")->assertStatus(405);
});
