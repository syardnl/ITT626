<?php

use App\Enums\PasswordTokenPurpose;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\PasswordLink;
use App\Services\Auth\PasswordLinks;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

function requestReset(string $email)
{
    return test()->postJson('/api/v1/auth/forgot-password', ['email' => $email]);
}

it('answers a reset request identically whether or not the email has an account', function () {
    account(Role::Resident, ['email' => 'rina@example.test']);

    $known = requestReset('rina@example.test');
    $unknown = requestReset('nobody@example.test');

    $known->assertStatus(202)->assertExactJson([
        'message' => 'If an account exists for that email, we have sent a link to reset its password.',
    ]);
    expect($unknown->status())->toBe(202)->and($unknown->json())->toBe($known->json());
});

it('keeps the token out of the query string, so servers never log it', function () {
    $rina = account(Role::Resident, ['email' => 'rina@example.test']);
    requestReset('rina@example.test');

    Notification::assertSentTo($rina, PasswordLink::class,
        fn (PasswordLink $link) => parse_url($link->url(), PHP_URL_QUERY) === null
            && str_starts_with(parse_url($link->url(), PHP_URL_FRAGMENT), 'token='));
});

it('sends a reset link only to a known, active account', function () {
    $rina = account(Role::Resident, ['email' => 'rina@example.test']);
    $gone = User::factory()->inactive()->withRole(Role::Resident)->create(['email' => 'gone@example.test']);

    requestReset('rina@example.test');
    requestReset('gone@example.test');
    requestReset('nobody@example.test');

    Notification::assertSentTo($rina, PasswordLink::class, function (PasswordLink $link) {
        return str_starts_with($link->url(), config('app.url').'/reset-password#token=');
    });
    Notification::assertNotSentTo($gone, PasswordLink::class);
    Notification::assertSentTimes(PasswordLink::class, 1);
});

/** The token from the most recent link emailed to the account. */
function tokenSentTo(User $user): string
{
    $token = null;
    Notification::assertSentTo($user, PasswordLink::class, function (PasswordLink $link) use (&$token) {
        parse_str(parse_url($link->url(), PHP_URL_FRAGMENT), $query);
        $token = $query['token'];

        return true;
    });

    return $token;
}

function resetPassword(string $token, string $password = 'new-secret-123')
{
    return test()->postJson('/api/v1/auth/reset-password', [
        'token' => $token, 'password' => $password, 'password_confirmation' => $password,
    ]);
}

it('sets the new password from a valid link, so the account signs in with it', function () {
    $rina = account(Role::Resident, ['email' => 'rina@example.test', 'password' => 'old-secret-123']);
    requestReset('rina@example.test');

    resetPassword(tokenSentTo($rina))
        ->assertOk()
        ->assertExactJson(['message' => 'Your password has been set. You can now sign in.']);

    $this->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'old-secret-123'])->assertStatus(422);
    $this->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'new-secret-123'])->assertOk();
});

it('accepts a reset link up to 60 minutes old and rejects it after', function () {
    $rina = account(Role::Resident, ['email' => 'rina@example.test']);

    requestReset('rina@example.test');
    $this->travel(59)->minutes();
    resetPassword(tokenSentTo($rina))->assertOk();

    requestReset('rina@example.test');
    $this->travel(61)->minutes();
    resetPassword(tokenSentTo($rina))
        ->assertStatus(422)
        ->assertJsonPath('errors.token.0', 'This link is invalid or has expired. Request a new one.');
});

it('accepts a link only once', function () {
    $rina = account(Role::Resident, ['email' => 'rina@example.test']);
    requestReset('rina@example.test');
    $token = tokenSentTo($rina);

    resetPassword($token, 'first-secret-123')->assertOk();
    resetPassword($token, 'second-secret-123')->assertStatus(422);

    $this->postJson('/api/v1/auth/login', ['email' => 'rina@example.test', 'password' => 'first-secret-123'])->assertOk();
});

it('rejects a made-up token', function () {
    resetPassword(str_repeat('x', 64))->assertStatus(422)->assertJsonValidationErrors('token');
});

it('retires an earlier unused link when a new one is requested', function () {
    $rina = account(Role::Resident, ['email' => 'rina@example.test']);
    requestReset('rina@example.test');
    $first = tokenSentTo($rina);
    Notification::fake();
    requestReset('rina@example.test');

    resetPassword($first)->assertStatus(422);
    resetPassword(tokenSentTo($rina))->assertOk();
});

it('refuses a valid link once the account has been deactivated', function () {
    $rina = account(Role::Resident, ['email' => 'rina@example.test']);
    requestReset('rina@example.test');
    $rina->update(['active' => false]);

    resetPassword(tokenSentTo($rina))->assertStatus(422);
});

it('requires a confirmed password of at least 8 characters', function () {
    $rina = account(Role::Resident, ['email' => 'rina@example.test']);
    requestReset('rina@example.test');
    $token = tokenSentTo($rina);

    resetPassword($token, 'short')->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token, 'password' => 'new-secret-123', 'password_confirmation' => 'different-123',
    ])->assertStatus(422)->assertJsonValidationErrors('password');

    resetPassword($token)->assertOk();
});

it('completes a 72-hour set-password link through the same endpoint', function () {
    // Issued directly: the endpoint that sends set-password links arrives with Accounts (#7).
    $newcomer = User::factory()->withRole(Role::Resident)->create(['email' => 'new@example.test', 'password' => null]);

    app(PasswordLinks::class)->send($newcomer, PasswordTokenPurpose::SetPassword);
    Notification::assertSentTo($newcomer, PasswordLink::class,
        fn (PasswordLink $link) => str_starts_with($link->url(), config('app.url').'/set-password#token='));

    $this->travel(71)->hours();
    resetPassword(tokenSentTo($newcomer))->assertOk();
    $this->postJson('/api/v1/auth/login', ['email' => 'new@example.test', 'password' => 'new-secret-123'])->assertOk();
});

it('expires a set-password link after 72 hours', function () {
    $newcomer = User::factory()->withRole(Role::Resident)->create(['password' => null]);

    app(PasswordLinks::class)->send($newcomer, PasswordTokenPurpose::SetPassword);
    $this->travel(73)->hours();

    resetPassword(tokenSentTo($newcomer))->assertStatus(422);
});
