<?php

namespace App\Models;

use App\Enums\Availability;
use App\Enums\TechnicianType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** Can be given new work: not On leave, and the account is active (design §6.2). */
    public function scopeCanTakeNewWork(Builder $query): void
    {
        $query->where('availability', '!=', Availability::OnLeave)->whereHas('user', fn (Builder $user) => $user->where('active', true));
    }

    /** Why this Technician cannot be given new work, or null when they can; see scopeCanTakeNewWork. */
    public function newWorkRefusal(): ?string
    {
        return match (true) {
            ! $this->user->active => "{$this->user->name}’s account is deactivated.",
            $this->availability === Availability::OnLeave => "{$this->user->name} is on leave and cannot take new work.",
            default => null,
        };
    }

    /** Adds `active_tasks`, the count of their Active Tasks (Assignment::scopeActiveTask). */
    public function scopeWithActiveTasks(Builder $query): void
    {
        $query->withCount(['assignments as active_tasks' => fn (Builder $assignments) => $assignments->activeTask()]);
    }

    /** Whether one of their Skills is for this Category (one Skill per Category). */
    public function hasSkillFor(Category $category): bool
    {
        return $this->skills->contains('category_id', $category->id);
    }

    /** Reached capacity: at or above maximum tasks, counting Active Tasks only. */
    public function isAtCapacity(int $activeTasks): bool
    {
        return $activeTasks >= $this->max_tasks;
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
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
