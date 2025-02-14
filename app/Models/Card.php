<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tag(){
        return $this->hasMany(Tag::class);
    }

    public function theme(){
        return $this->belongsTo(Theme::class);
    }

    public function wordbox()
    {
        return $this->belongsToMany(Wordbox::class, 'wordbox_card', 'card_id', 'wordbox_id');
    }
}
