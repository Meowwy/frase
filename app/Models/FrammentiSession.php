<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One learner's planned Frammenti session in one language on one local date: how many
 * batches were promised and how many are done. A day may hold several, one unfinished at
 * a time. See docs/frammenti.md "Sessions".
 */
class FrammentiSession extends Model
{
    protected $guarded = [];

    // `date` stays a plain Y-m-d string: a date cast would store a time with it and break
    // comparing it with the user's local date.
    protected $casts = [
        'planned_batches' => 'integer',
        'batches_done' => 'integer',
        'finished_at' => 'datetime',
    ];
}
