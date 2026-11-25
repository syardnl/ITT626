<?php

use App\Enums\Role;
use App\Notifications\PasswordLink;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('never stores the emailed token in plain text, in the token table or the job queue', function () {
    config(['queue.default' => 'database']);
    account(Role::Resident, ['email' => 'rina@example.test']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'rina@example.test'])->assertStatus(202);

    // Recover the token the way the queue worker will, to know what to look for.
    $job = DB::table('jobs')->sole();
    $command = json_decode($job->payload, true)['data']['command'];
    $token = (fn () => $this->token)->call(unserialize(decrypt($command))->notification);

    expect($token)->toHaveLength(64)
        ->and($job->payload)->not->toContain($token)
        ->and(DB::table('password_tokens')->sole()->token_hash)->toBe(hash('sha256', $token))
        ->and(json_encode(DB::table('password_tokens')->get()))->not->toContain($token);
});

it('puts the reset email on the queue instead of sending it during the request', function () {
    Queue::fake();
    $rina = account(Role::Resident, ['email' => 'rina@example.test']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'rina@example.test'])->assertStatus(202);

    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notification instanceof PasswordLink
        && $job->notifiables->first()->is($rina));
});
