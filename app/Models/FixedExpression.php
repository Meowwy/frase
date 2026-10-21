<?php

namespace App\Models;

use App\Models\Concerns\HasSynonyms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One entry in the learner's expression base: a fixed expression (*inte bara … utan
 * också*, *på grund av*, *tycka om*) in its canonical form, with its native translation and
 * when it was last recalled. Like a base word, its translation is set once at creation and
 * never revised; it has no card schedule, only its Frammenti schedule (docs/frammenti.md).
 * Deleting it works as for a base word (see BaseWord), except it can't be marked known.
 * See docs/cards.md "The expression base".
 */
class FixedExpression extends Model
{
    use HasSynonyms;

    protected $guarded = [];

    protected $casts = [
        'last_recalled_at' => 'datetime',
        'frammenti_level' => 'integer',
        'deleted' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('notDeleted', fn (Builder $query) => $query->where($query->qualifyColumn('deleted'), false));
    }

    /**
     * Find or create the expression-base entry for one proposed fixed expression, bringing a
     * deleted one back — the counterpart of BaseWord::resolve(). `form` is NOCASE, so the
     * match is case-insensitive.
     */
    public static function resolve(User $user, Language $language, string $form, string $translation): self
    {
        $expression = self::withoutGlobalScope('notDeleted')->firstOrCreate(
            ['user_id' => $user->id, 'language_id' => $language->id, 'form' => $form],
            ['translation' => $translation],
        );

        if ($expression->deleted) {
            $expression->update(['deleted' => false]);
        }

        return $expression;
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

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
