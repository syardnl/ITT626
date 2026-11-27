<?php

namespace App\Models;

use App\Enums\Priority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Manager confirming or changing a Complaint's Category and Priority, with the override reason. */
class ClassificationChange extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'complaint_classification_changes';

    protected $fillable = ['changed_by', 'from_category_id', 'to_category_id', 'from_priority', 'to_priority', 'reason'];

    protected function casts(): array
    {
        return [
            'from_priority' => Priority::class,
            'to_priority' => Priority::class,
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function fromCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'from_category_id');
    }

    public function toCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'to_category_id');
    }
}
