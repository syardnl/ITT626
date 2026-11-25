<?php

namespace App\Enums;

/** Why a password link was issued; decides its expiry, path and wording. */
enum PasswordTokenPurpose: string
{
    /** "Forgot password": valid once for 60 minutes. */
    case Reset = 'reset';

    /** New account: the holder chooses their first password, valid once for 72 hours. */
    case SetPassword = 'set_password';

    public function lifetimeMinutes(): int
    {
        return match ($this) {
            self::Reset => 60,
            self::SetPassword => 72 * 60,
        };
    }

    /** Human wording of the lifetime, for emails: "60 minutes", "72 hours". */
    public function lifetimeLabel(): string
    {
        $minutes = $this->lifetimeMinutes();

        return $minutes % 60 === 0 && $minutes > 60 ? ($minutes / 60).' hours' : $minutes.' minutes';
    }

    /** SPA screen that completes the link; must match resources/js/routes/router.tsx. */
    public function spaPath(): string
    {
        return match ($this) {
            self::Reset => '/reset-password',
            self::SetPassword => '/set-password',
        };
    }
}
