<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One entry in the learner's expression base: a fixed expression (*inte bara … utan
 * också*, *på grund av*, *tycka om*) in its canonical form, with its native translation and
 * when it was last recalled. Like a base word, its translation is set once at creation and
 * never revised, and it has no schedule of its own. See docs/cards.md "The expression base".
 */
class FixedExpression extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_recalled_at' => 'datetime',
    ];

    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(Card::class, 'card_fixed_expression');
    }
}
