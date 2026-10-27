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
        'base_word_ids',
        'theme_preference',
        'title',
        'text_with_gaps',
        'correct_answers',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'base_word_ids' => 'array',
            'correct_answers' => 'array',
        ];
    }

    public function wordbox(): BelongsTo
    {
        return $this->belongsTo(Wordbox::class);
    }
}
