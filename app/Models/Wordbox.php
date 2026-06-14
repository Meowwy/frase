<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wordbox extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function cards()
    {
        return $this->belongsToMany(Card::class, 'wordbox_card', 'wordbox_id', 'card_id');
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
