<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'embedding' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tag()
    {
        return $this->hasMany(Tag::class);
    }

    public function theme()
    {
        return $this->belongsTo(Theme::class);
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }

    public function scopeForLanguage($query, $languageId)
    {
        return $query->where('language_id', $languageId);
    }

    public function wordbox()
    {
        return $this->belongsToMany(Wordbox::class, 'wordbox_card', 'card_id', 'wordbox_id');
    }

    public function synonyms()
    {
        return $this->hasMany(Synonym::class);
    }

    /**
     * Cards the user has manually linked to this one. Stored in the `synonyms`
     * pivot as two mirrored rows per link, so the relation reads symmetrically.
     */
    public function linkedCards()
    {
        return $this->belongsToMany(Card::class, 'synonyms', 'card_id', 'synonym_card_id')
            ->withTimestamps();
    }

    public function relatedTerms()
    {
        return $this->hasMany(RelatedTerm::class);
    }
}
