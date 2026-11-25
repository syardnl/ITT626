<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A row of the `roles` table. Application code names roles with the App\Enums\Role enum. */
class Role extends Model
{
    protected $fillable = ['name'];
}
