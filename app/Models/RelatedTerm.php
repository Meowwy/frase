<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RelatedTerm extends Model
{
    protected $guarded = [];

    public function card()
    {
        return $this->belongsTo(Card::class);
    }

    public function relatedCard()
    {
        return $this->belongsTo(Card::class, 'related_card_id');
    }
}
