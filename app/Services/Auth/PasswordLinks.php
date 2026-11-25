<?php

namespace App\Services\Auth;

use App\Enums\AuditAction;
use App\Enums\PasswordTokenPurpose;
use App\Models\PasswordToken;
use App\Models\User;
use App\Notifications\PasswordLink;
use App\Services\Audit\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and redeems single-use password links: "forgot password" resets and
 * new-account set-password links share one mechanism and one endpoint.
 */
class PasswordLinks
{
    public function __construct(private readonly AuditTrail $audit) {}

    /** Issue a fresh link (superseding any unused one) and queue it to the account holder. */
    public function send(User $user, PasswordTokenPurpose $purpose): void
    {
        $token = Str::random(64);

        PasswordToken::query()->where('user_id', $user->id)->whereNull('used_at')->delete();

        PasswordToken::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'token_hash' => self::hash($token),
            'expires_at' => now()->addMinutes($purpose->lifetimeMinutes()),
        ]);

        $user->notify(new PasswordLink($purpose, $token));
    }

    /**
     * Set a new password from an emailed token. Returns false, changing nothing,
     * when the token is unknown, expired, already used, or its account is inactive.
     */
    public function redeem(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): bool
    {
        return DB::transaction(function () use ($token, $password) {
            $record = PasswordToken::query()
                ->where('token_hash', self::hash($token))
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $record || ! $record->user->active) {
                return false;
            }

            $record->update(['used_at' => now()]);
            $record->user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

            $this->audit->record(
                AuditAction::PasswordSet,
                $record->user,
                changes: ['password' => true],
                metadata: ['link' => $record->purpose->value],
                actor: $record->user,
            );

            return true;
        });
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
