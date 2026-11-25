<?php

namespace App\Models;

use App\Enums\ResidentType;
use App\Enums\Role;
use App\Models\Role as RoleModel;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /** Replace whatever role the account held: an account holds exactly one. */
    public function assignRole(Role $role): void
    {
        $this->roles()->sync([RoleModel::where('name', $role->value)->value('id')]);
        $this->unsetRelation('roles');
    }
}
