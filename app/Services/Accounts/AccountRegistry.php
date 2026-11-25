<?php

namespace App\Services\Accounts;

use App\Enums\AuditAction;
use App\Enums\PasswordTokenPurpose;
use App\Enums\Role;
use App\Models\Property;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Auth\PasswordLinks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates and changes accounts at the Administrator's counter. Accounts are never
 * deleted; each holds exactly one role, starts without a password and receives a
 * set-password link. Every change is audited.
 */
class AccountRegistry
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly PasswordLinks $links,
    ) {}

    /** @param  array{name: string, email: string, role: string, unit_id?: ?int, resident_type?: ?string}  $attributes */
    public function create(array $attributes): User
    {
        return DB::transaction(function () use ($attributes) {
            $role = Role::from($attributes['role']);
            $resident = $role === Role::Resident;

            $user = User::create([
                'property_id' => Property::current()->id,
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'unit_id' => $resident ? $attributes['unit_id'] : null,
                'resident_type' => $resident ? $attributes['resident_type'] : null,
                'active' => true,
            ]);
            $user->assignRole($role);

            $this->audit->recordChange(AuditAction::Created, $user, alsoChanged: ['role' => ['from' => null, 'to' => $role->value]]);
            $this->sendInvite($user);

            return $user->refresh();
        });
    }

    /**
     * Apply an edit. A role other than Resident clears the Unit and Resident Type.
     * Toggling `active` deactivates or reactivates the account, audited separately.
     *
     * @throws AccountConflict
     */
    public function update(User $user, array $attributes): User
    {
        return DB::transaction(function () use ($user, $attributes) {
            $active = array_key_exists('active', $attributes) ? (bool) $attributes['active'] : $user->active;
            $from = $user->role();
            $to = isset($attributes['role']) ? Role::from($attributes['role']) : $from;
            unset($attributes['active'], $attributes['role']);

            $user->fill($attributes);
            if ($to !== Role::Resident) {
                $user->fill(['unit_id' => null, 'resident_type' => null]);
            }

            if ($user->isDirty() || $to !== $from) {
                $user->save();
                $roleChange = [];
                if ($to !== $from) {
                    $user->assignRole($to);
                    $roleChange = ['role' => ['from' => $from?->value, 'to' => $to->value]];
                }
                $this->audit->recordChange(AuditAction::Updated, $user, alsoChanged: $roleChange);
            }

            if ($active !== $user->active) {
                $active ? $this->reactivate($user) : $this->deactivate($user);
            }

            return $user->refresh();
        });
    }

    /**
     * Issue a fresh set-password link, superseding the last one.
     *
     * @throws AccountConflict once the account holder has set a password
     */
    public function resendInvite(User $user): void
    {
        if ($user->password !== null) {
            throw new AccountConflict('This person has already set a password. They can use "Forgot password" instead.');
        }
        if (! $user->active) {
            throw new AccountConflict('This account is deactivated. Reactivate it before sending a link.');
        }

        $this->sendInvite($user, resent: true);
    }

    private function sendInvite(User $user, bool $resent = false): void
    {
        $this->links->send($user, PasswordTokenPurpose::SetPassword);
        $this->audit->record(AuditAction::InviteSent, $user, metadata: ['resent' => $resent]);
    }

    /** Blocks sign-in and ends every session the account has, effective on its next request. */
    private function deactivate(User $user): void
    {
        $user->active = false;
        $user->save();
        $this->audit->recordChange(AuditAction::Deactivated, $user);

        // Invalidate "remember me" cookies and stored sessions too; the `active`
        // middleware catches any session that is still in flight.
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }

    private function reactivate(User $user): void
    {
        if ($user->hasRole(Role::Resident) && ! $user->unit()->where('active', true)->exists()) {
            throw new AccountConflict("This Resident's Unit is deactivated. Reactivate the Unit or move the Resident to another Unit first.");
        }

        $user->active = true;
        $user->save();
        $this->audit->recordChange(AuditAction::Reactivated, $user);
    }
}
