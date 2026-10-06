<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One fixed expression CALL 1 found in a proposal's Term, waiting to be carried onto
 * `fixed_expressions` at approval unless the learner strikes it. Striking it is per
 * proposal — unlike a word, a fixed expression is never remembered as known.
 */
class ProposalFixedExpression extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'struck' => 'boolean',
    ];
}
