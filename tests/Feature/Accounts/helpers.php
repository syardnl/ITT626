<?php

use App\Enums\Role;
use App\Models\User;
use App\Notifications\PasswordLink;
use Illuminate\Support\Facades\Notification;

/** Signs in as an Administrator and builds Block A > Floor A-01 > Units A-01-01, A-01-02. */
function accountsSetup(): array
{
    test()->actingAs($admin = account(Role::Administrator, ['name' => 'Adam Admin', 'email' => 'adam@example.test']));
    $post = fn (array $body) => test()->postJson('/api/v1/admin/locations', $body)->assertCreated()->json('data');

    $block = $post(['type' => 'block', 'parent_id' => null, 'code' => 'A', 'name' => 'Block A']);
    $floor = $post(['type' => 'floor', 'parent_id' => $block['id'], 'code' => 'A-01', 'name' => 'Block A, Floor 1']);
    $unit = $post(['type' => 'unit', 'parent_id' => $floor['id'], 'code' => 'A-01-01', 'name' => 'Unit A-01-01']);
    $other = $post(['type' => 'unit', 'parent_id' => $floor['id'], 'code' => 'A-01-02', 'name' => 'Unit A-01-02']);

    return compact('admin', 'block', 'floor', 'unit', 'other');
}

function createAccount(array $body)
{
    return test()->postJson('/api/v1/admin/users', $body);
}

function residentBody(int $unitId, array $overrides = []): array
{
    return ['name' => 'Rina Resident', 'email' => 'rina@example.test', 'role' => 'resident', 'unit_id' => $unitId, 'resident_type' => 'tenant', ...$overrides];
}

/** The token in the latest set-password link sent to this email. */
function inviteTokenFor(string $email): string
{
    $user = User::where('email', $email)->firstOrFail();
    $token = null;
    Notification::assertSentTo($user, PasswordLink::class, function (PasswordLink $link) use (&$token) {
        parse_str((string) parse_url($link->url(), PHP_URL_FRAGMENT), $fragment);
        $token = $fragment['token'];

        return true;
    });

    return $token;
}
