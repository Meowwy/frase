<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One meaning of a base word (*bank* → "bank", "riverside"). The base word stays one row per
 * lemma + part of speech; a card's link to it records which meaning that card uses
 * (`card_base_word.base_word_sense_id`). See docs/cards.md "The vocabulary base".
 */
class BaseWordSense extends Model
{
    protected $guarded = [];

    public function baseWord(): BelongsTo
    {
        return $this->belongsTo(BaseWord::class);
    }
}
