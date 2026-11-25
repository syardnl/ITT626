<?php

namespace App\Models;

use App\Enums\Priority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One of the ten fixed kinds of maintenance problem; source ERD: ISSUE_CATEGORY. */
class Category extends Model
{
    protected $table = 'complaint_categories';

    protected $fillable = ['key', 'name', 'default_priority', 'active'];

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return [
            'default_priority' => Priority::class,
            'active' => 'boolean',
        ];
    }

    public function skill(): HasOne
    {
        return $this->hasOne(Skill::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
