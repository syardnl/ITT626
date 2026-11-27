<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The reporter's optional 1–5 score and feedback, given when confirming the repair; at most one per Complaint. */
class Rating extends Model
{
    protected $fillable = ['user_id', 'score', 'feedback'];

    protected function casts(): array
    {
        return ['score' => 'integer'];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
