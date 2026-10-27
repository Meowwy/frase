<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Wordbox extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Base words of the wordbox's own language only — the controller checks that on every
     * attach. A deleted base word drops out (its global scope) but keeps its pivot row.
     */
    public function baseWords(): BelongsToMany
    {
        return $this->belongsToMany(BaseWord::class);
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }

    public function scopeForLanguage($query, $languageId)
    {
        return $query->where('language_id', $languageId);
    }

    public function gapFillExercises()
    {
        return $this->hasMany(GapFillExercise::class);
    }

    public function latestGapFillExercise()
    {
        return $this->hasOne(GapFillExercise::class)->latestOfMany();
    }
}
