<?php

namespace App\Models;

use App\Enums\Priority;
use Illuminate\Database\Eloquent\Model;

/** The SLA Target for one Priority: minutes allowed for Response and for Resolution, in calendar time (ADR 0004). */
class SlaRule extends Model
{
    protected $fillable = ['priority', 'response_minutes', 'resolution_minutes', 'version'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
            'version' => 'integer',
        ];
    }
}
