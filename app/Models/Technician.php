<?php

namespace App\Models;

use App\Enums\Availability;
use App\Enums\TechnicianType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** The profile of one Technician account: type, Skills, Availability, capacity and home Block. */
class Technician extends Model
{
    /** Relations every rendering of a profile needs (TechnicianResource). */
    public const DISPLAY_RELATIONS = ['user', 'skills', 'homeBlock'];

    protected $fillable = ['user_id', 'type', 'company_name', 'availability', 'max_tasks', 'home_block_id'];

    protected function casts(): array
    {
        return [
            'type' => TechnicianType::class,
            'availability' => Availability::class,
            'max_tasks' => 'integer',
        ];
    }

    public function isProfileOf(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function homeBlock(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'home_block_id');
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class)->orderBy('name');
    }
}
