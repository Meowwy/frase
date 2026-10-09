<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One entry in the learner's expression base: a fixed expression (*inte bara … utan
 * också*, *på grund av*, *tycka om*) in its canonical form, with its native translation and
 * when it was last recalled. Like a base word, its translation is set once at creation and
 * never revised; it has no card schedule, only its Frammenti progress (docs/frammenti.md).
 * See docs/cards.md "The expression base".
 */
class FixedExpression extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_recalled_at' => 'datetime',
        'frammenti_tier' => 'integer',
        'frammenti_correct_streak' => 'integer',
        'frammenti_wrong_streak' => 'integer',
        'frammenti_rest_until' => 'datetime',
    ];

    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(Card::class, 'card_fixed_expression');
    }

    /**
     * The form as shown — the counterpart of BaseWord::displayForm(), so the /base list can
     * render both bases' entries alike.
     */
    public function displayForm(): string
    {
        return $this->form;
    }
}
