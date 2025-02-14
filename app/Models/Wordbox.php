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


    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
