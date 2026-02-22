<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GapFillExercise extends Model
{
    use HasFactory;

    protected $fillable = [
        'wordbox_id',
        'theme_preference',
        'text_with_gaps',
        'correct_answers',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'correct_answers' => 'array',
        ];
    }

    public function wordbox(): BelongsTo
    {
        return $this->belongsTo(Wordbox::class);
    }
}
