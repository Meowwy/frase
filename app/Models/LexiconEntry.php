<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One word sense from a downloaded dictionary — see App\Support\Lexicon.
 */
class LexiconEntry extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
