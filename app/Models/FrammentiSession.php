<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One learner's planned Frammenti session for one language on one local date: how many
 * batches were promised, how many are done, the new items introduced so far and the
 * repetitions still owed. See docs/frammenti.md "The daily session".
 */
class FrammentiSession extends Model
{
    protected $guarded = [];

    // `date` stays a plain Y-m-d string: a date cast would store a time with it and break
    // comparing it with the user's local date.
    protected $casts = [
        'planned_batches' => 'integer',
        'batches_done' => 'integer',
        'introduced' => 'integer',
        'owed' => 'array',
        'finished_at' => 'datetime',
    ];
}
