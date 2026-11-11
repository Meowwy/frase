<?php

namespace App\Models;

use App\Models\Concerns\MatchesLemma;
use Illuminate\Database\Eloquent\Model;

/**
 * A word the learner struck in staging: a lemma + part of speech in one language that is
 * never proposed as a new base word again. Un-ignoring it deletes the row.
 * See docs/cards.md "Staging".
 */
class IgnoredWord extends Model
{
    use MatchesLemma;

    protected $guarded = [];
}
