<?php

use App\Enums\PasswordTokenPurpose;
use App\Models\User;
use App\Notifications\PasswordLink;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Notification::fake());

it('creates a Resident linked to an active Unit with a Resident Type', function () {
    ['unit' => $unit] = accountsSetup();

    createAccount(residentBody($unit['id']))->assertCreated()
        ->assertJsonPath('data.name', 'Rina Resident')
        ->assertJsonPath('data.role', 'resident')
        ->assertJsonPath('data.unit.code', 'A-01-01')
        ->assertJsonPath('data.resident_type', 'tenant')
        ->assertJsonPath('data.active', true)
        ->assertJsonPath('data.password_set', false)
        ->assertJsonMissingPath('data.password');
});

it('creates staff accounts for every other role, with no Unit', function (string $role) {
    accountsSetup();

    createAccount(['name' => 'Sam Staff', 'email' => 'sam@example.test', 'role' => $role])->assertCreated()
        ->assertJsonPath('data.role', $role)
        ->assertJsonPath('data.unit', null)
        ->assertJsonPath('data.resident_type', null);
})->with(['manager', 'technician', 'administrator']);

it('rejects a duplicate email, whatever its case', function () {
    ['unit' => $unit] = accountsSetup();
    createAccount(residentBody($unit['id']))->assertCreated();

    createAccount(residentBody($unit['id'], ['email' => ' RINA@Example.TEST ']))->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'Another account already uses this email.');
});

it('requires a name, a valid email and one known role', function () {
    accountsSetup();

    createAccount(['email' => 'not-an-email', 'role' => 'janitor'])->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email', 'role']);
});

it('requires a Resident to have an active Unit and a Resident Type', function () {
    ['unit' => $unit, 'floor' => $floor] = accountsSetup();

    createAccount(residentBody($unit['id'], ['unit_id' => null, 'resident_type' => null]))->assertStatus(422)
        ->assertJsonPath('errors.unit_id.0', 'A Resident must be linked to a Unit.')
        ->assertJsonValidationErrors('resident_type');
    createAccount(residentBody($floor['id']))->assertStatus(422)->assertJsonPath('errors.unit_id.0', 'Choose an active Unit.');
    createAccount(residentBody($unit['id'], ['resident_type' => 'landlord']))->assertStatus(422)->assertJsonValidationErrors('resident_type');

    $this->patchJson("/api/v1/admin/locations/{$unit['id']}", ['active' => false])->assertOk();
    createAccount(residentBody($unit['id']))->assertStatus(422)->assertJsonPath('errors.unit_id.0', 'Choose an active Unit.');
});

it('refuses a Unit or Resident Type on a staff account', function () {
    ['unit' => $unit] = accountsSetup();

    createAccount(['name' => 'Mariam', 'email' => 'mariam@example.test', 'role' => 'manager', 'unit_id' => $unit['id'], 'resident_type' => 'owner'])
        ->assertStatus(422)
        ->assertJsonPath('errors.unit_id.0', 'Only Resident accounts are linked to a Unit.')
        ->assertJsonPath('errors.resident_type.0', 'Only Resident accounts have a Resident Type.');
});

it('queues a set-password email when an account is created', function () {
    ['unit' => $unit] = accountsSetup();
    Queue::fake();
    Notification::swap(new ChannelManager(app())); // real notifications, so they reach the faked queue

    createAccount(residentBody($unit['id']))->assertCreated();

    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notification instanceof PasswordLink
        && $job->notification->purpose === PasswordTokenPurpose::SetPassword);
});

it('lets the new account holder set a password from the link and sign in', function () {
    ['unit' => $unit] = accountsSetup();
    createAccount(residentBody($unit['id']))->assertCreated();
    $token = inviteTokenFor('rina@example.test');

    Notification::assertSentTo(User::firstWhere('email', 'rina@example.test'), PasswordLink::class,
        fn (PasswordLink $link) => str_starts_with($link->url(), config('app.url').'/set-password#token='));

    auth()->guard('web')->logout();
    $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'password' => 'a-long-new-password', 'password_confirmation' => 'a-long-new-password'])->assertOk();
    $this->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'a-long-new-password'])
        ->assertOk()->assertJsonPath('data.role', 'resident');
});

it('makes the set-password link work once', function () {
    ['unit' => $unit] = accountsSetup();
    createAccount(residentBody($unit['id']))->assertCreated();
    $token = inviteTokenFor('rina@example.test');
    $body = ['token' => $token, 'password' => 'a-long-new-password', 'password_confirmation' => 'a-long-new-password'];

    $this->postJson('/api/v1/auth/reset-password', $body)->assertOk();
    $this->postJson('/api/v1/auth/reset-password', [...$body, 'password' => 'another-password-1', 'password_confirmation' => 'another-password-1'])
        ->assertStatus(422)->assertJsonValidationErrors('token');
});

it('expires the set-password link after 72 hours', function (int $minutes, int $status) {
    ['unit' => $unit] = accountsSetup();
    createAccount(residentBody($unit['id']))->assertCreated();
    $token = inviteTokenFor('rina@example.test');

    $this->travel($minutes)->minutes();

    $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'password' => 'a-long-new-password', 'password_confirmation' => 'a-long-new-password'])
        ->assertStatus($status);
})->with([
    'just inside 72 hours' => [72 * 60 - 1, 200],
    'after 72 hours' => [72 * 60 + 1, 422],
]);
