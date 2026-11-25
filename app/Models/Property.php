<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The single apartment development the system manages (ADR 0003). */
class Property extends Model
{
    protected $fillable = ['name', 'timezone'];

    public static function current(): self
    {
        return static::query()->firstOrFail();
    }
}
