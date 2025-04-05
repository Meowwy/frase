<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GapFillExercise extends Model
{
    use HasFactory;

    protected $fillable = ['wordbox_id', 'text_with_gaps', 'used_words'];

}
