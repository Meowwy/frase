<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Synonym extends Model
{
    protected $guarded = [];

    public function card()
    {
        return $this->belongsTo(Card::class);
    }

    public function synonymCard()
    {
        return $this->belongsTo(Card::class, 'synonym_card_id');
    }
}
