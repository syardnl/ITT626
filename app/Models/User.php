<?php

namespace App\Models;

use App\Enums\ResidentType;
use App\Enums\Role;
use App\Models\Role as RoleModel;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['property_id', 'unit_id', 'name', 'email', 'password', 'resident_type', 'active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'resident_type' => ResidentType::class,
            'active' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(RoleModel::class, 'role_user', 'user_id', 'role_id')->withTimestamps();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'unit_id');
    }

    /** The Technician profile, for Technician accounts that have one. */
    public function technician(): HasOne
    {
        return $this->hasOne(Technician::class);
    }

    /** The account's single role. */
    public function role(): ?Role
    {
        $record = $this->roles->first();

        return $record ? Role::from($record->name) : null;
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role(), $roles, true);
    }

    /** Management-office staff: Managers and Administrators, who see every Complaint. */
    public function isOffice(): bool
    {
        return $this->hasRole(Role::Manager, Role::Administrator);
    }

    /**
     * Managers, Administrators and Technicians: on a Complaint they may open, they read Internal
     * Comments and the reporter's name. A Technician only ever opens their own (Complaint::visibleTo).
     */
    public function isStaff(): bool
    {
        return $this->isOffice() || $this->hasRole(Role::Technician);
    }

    /** The Block the Resident's Unit is in (Unit > Floor > Block), or null without a Unit. */
    public function blockId(): ?int
    {
        return $this->unit?->parent?->parent_id;
    }

    /** Replace whatever role the account held: an account holds exactly one. */
    public function assignRole(Role $role): void
    {
        $this->roles()->sync([RoleModel::where('name', $role->value)->value('id')]);
        $this->unsetRelation('roles');
    }
}
