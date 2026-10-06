<?php

namespace App\Models;

use App\Jobs\AnalyzeProposalJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * One captured Term awaiting approval in staging, with everything CALL 1 proposes for it:
 * the Term the card will be built around, its candidate base words and fixed expressions
 * and, for an ambiguous
 * lone word captured without a Context, the senses to pick from.
 *
 * A proposal is outside the vocabulary entirely. Approving it is the only way anything
 * enters, and it is the point CALL 2 finally runs — so no content is ever generated for
 * something the learner discards. See docs/cards.md "Staging".
 */
class Proposal extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const GROUP_PRESENT = 'present';

    public const GROUP_KNOWN = 'known';

    public const GROUP_NEW = 'new';

    protected $guarded = [];

    protected $casts = [
        'senses' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function baseWords(): HasMany
    {
        return $this->hasMany(ProposalBaseWord::class);
    }

    public function fixedExpressions(): HasMany
    {
        return $this->hasMany(ProposalFixedExpression::class);
    }

    /**
     * Whether Approve may fire: once CALL 1 has landed and, if it offered senses, one has
     * been picked. How many base words are linked never matters — a card may link none of
     * them, or many. The control disables itself on this rather than failing after the fact.
     */
    public function isApprovable(): bool
    {
        return $this->status === self::STATUS_COMPLETED && (bool) $this->language_id && empty($this->senses);
    }

    /**
     * Send the proposal back through CALL 1, after the learner corrected its language or
     * its Context. Everything CALL 1 produced was extracted for the old input, so it is
     * thrown away rather than patched. A language already set stays pinned (see
     * AnalyzeProposalJob).
     */
    public function reanalyze(array $attributes = []): void
    {
        $this->baseWords()->delete();
        $this->fixedExpressions()->delete();
        $this->update([...$attributes, 'senses' => null, 'status' => self::STATUS_PENDING]);

        AnalyzeProposalJob::dispatch($this);
    }

    /**
     * The key one candidate word is looked up in the vocabulary base under: the dedup key,
     * lemma **and** part of speech, inside this proposal's language.
     */
    public function presenceKeyFor(ProposalBaseWord $word): string
    {
        return $this->language_id.'|'.$word->lemma.'|'.$word->part_of_speech;
    }

    /**
     * Which group one candidate falls into, checked in this order: **already present** in
     * the vocabulary base (shown aside, linked on approval), **known** (struck once, shown
     * aside, never linked), or **new** (strikeable; created and linked on approval). Already
     * present wins if both ever apply. Takes the indexes so a whole list is resolved with
     * one query per store, never one per chip.
     */
    public function groupOf(ProposalBaseWord $word, SupportCollection $present, SupportCollection $known): string
    {
        $key = $this->presenceKeyFor($word);

        return match (true) {
            $present->has($key) => self::GROUP_PRESENT,
            $known->has($key) => self::GROUP_KNOWN,
            default => self::GROUP_NEW,
        };
    }

    /**
     * Which of a whole list's candidate words are **already in the vocabulary base**, as a
     * map from presenceKeyFor() to the entry approval would reuse rather than create.
     *
     * Never stored, always computed, so a word the learner acquires between capture and
     * approval is still recognised — but computed **once per list**, not once per chip:
     * `/staging/list` re-renders the whole tray every 2 seconds while anything is still
     * pending, and with no queue worker running that poll never stops.
     *
     * @param  \Illuminate\Support\Collection<int, self>  $proposals
     * @return \Illuminate\Support\Collection<string, BaseWord>
     */
    public static function presenceIndex(Collection|SupportCollection $proposals): SupportCollection
    {
        // The notice expands to the cards the word is already used in.
        return self::indexCandidates(BaseWord::with('cards:id,term'), $proposals);
    }

    /**
     * Which of a whole list's candidate words the learner has struck as **known**, keyed
     * like presenceIndex() and computed the same way: live, once per list.
     *
     * @param  \Illuminate\Support\Collection<int, self>  $proposals
     * @return \Illuminate\Support\Collection<string, KnownWord>
     */
    public static function knownIndex(Collection|SupportCollection $proposals): SupportCollection
    {
        return self::indexCandidates(KnownWord::query(), $proposals);
    }

    /**
     * The key one fixed expression is looked up in the expression base under: its form,
     * case-insensitively, inside this proposal's language.
     */
    public function expressionKeyFor(ProposalFixedExpression $expression): string
    {
        return $this->language_id.'|'.mb_strtolower($expression->form);
    }

    /**
     * Which of a whole list's fixed expressions are **already in the expression base**,
     * keyed by expressionKeyFor(). Live and once per list, like presenceIndex().
     *
     * @param  \Illuminate\Support\Collection<int, self>  $proposals
     * @return \Illuminate\Support\Collection<string, FixedExpression>
     */
    public static function expressionPresenceIndex(Collection|SupportCollection $proposals): SupportCollection
    {
        $forms = $proposals->flatMap(fn (self $proposal) => $proposal->fixedExpressions)->pluck('form')->unique();

        if ($forms->isEmpty()) {
            return collect();
        }

        // `form` is a NOCASE column, so this match is case-insensitive.
        return FixedExpression::whereIn('user_id', $proposals->pluck('user_id')->unique())
            ->whereIn('language_id', $proposals->pluck('language_id')->filter()->unique())
            ->whereIn('form', $forms)
            ->get()
            ->keyBy(fn (FixedExpression $expression) => $expression->language_id.'|'.mb_strtolower($expression->form));
    }

    /**
     * The rows of one store (base words or known words) matching any candidate of the
     * list, keyed like presenceKeyFor().
     */
    private static function indexCandidates(Builder $query, Collection|SupportCollection $proposals): SupportCollection
    {
        $candidates = $proposals->flatMap(fn (self $proposal) => $proposal->baseWords);
        $languageIds = $proposals->pluck('language_id')->filter()->unique();

        if ($candidates->isEmpty() || $languageIds->isEmpty()) {
            return collect();
        }

        return $query->whereIn('user_id', $proposals->pluck('user_id')->unique())
            ->whereIn('language_id', $languageIds)
            ->whereIn('lemma', $candidates->pluck('lemma')->unique())
            ->whereIn('part_of_speech', $candidates->pluck('part_of_speech')->unique())
            ->get()
            ->keyBy(fn ($word) => $word->language_id.'|'.$word->lemma.'|'.$word->part_of_speech);
    }

    /**
     * The card the learner already has for this exact Term, if any. The duplicate check
     * narrows to the Term alone: owning a base word of a Term is a different fact, and
     * the chip tray's already-present notice is what surfaces that one.
     */
    public function duplicateCard(): ?Card
    {
        if (blank($this->term) || ! $this->language_id) {
            return null;
        }

        return Card::where('user_id', $this->user_id)
            ->forLanguage($this->language_id)
            ->matchingTerm($this->term)
            ->first();
    }

    /**
     * Write the card, its base-word and fixed-expression links and the base entries they
     * need, then run CALL 2
     * for its content. Returns the new card, or null when CALL 2 fails — in which case
     * nothing is written and the proposal is still sitting in staging.
     *
     * The base rows are created first and reused rather than inserted blindly, so two
     * proposals for the same lemma and part of speech approved in either order both land
     * on one row (see BaseWord::resolve).
     */
    public function approve(): ?Card
    {
        $language = $this->language;
        $user = $this->user;

        $content = Card::generateContent($user, $language, $this->term, $this->context);

        if (is_null($content)) {
            return null;
        }

        $present = self::presenceIndex(collect([$this]));
        $known = self::knownIndex(collect([$this]));
        $presentExpressions = self::expressionPresenceIndex(collect([$this]));

        $card = DB::transaction(function () use ($user, $language, $content, $present, $known, $presentExpressions) {
            $card = Card::persist($user, $language, $this->term, $this->context, $content);

            // Known words are never linked; already-present and new ones both resolve below.
            $linked = $this->baseWords->reject(fn (ProposalBaseWord $word) => $this->groupOf($word, $present, $known) === self::GROUP_KNOWN);

            foreach ($linked as $candidate) {
                $baseWord = BaseWord::resolve(
                    $user,
                    $language,
                    $candidate->lemma,
                    $candidate->part_of_speech,
                    $candidate->grammar_attributes,
                    $candidate->translation,
                    $candidate->dictionary_form,
                );

                $card->baseWords()->attach($baseWord->id, ['surface_form' => $candidate->surface_form]);
            }

            // A struck expression is skipped unless it has since entered the expression base,
            // in which case it is shown as already present and linked like any other.
            $expressions = $this->fixedExpressions->reject(
                fn (ProposalFixedExpression $expression) => $expression->struck && ! $presentExpressions->has($this->expressionKeyFor($expression))
            );

            foreach ($expressions as $candidate) {
                $expression = FixedExpression::firstOrCreate(
                    ['user_id' => $user->id, 'language_id' => $language->id, 'form' => $candidate->form],
                    ['translation' => $candidate->translation],
                );

                $card->fixedExpressions()->attach($expression->id, ['surface_form' => $candidate->surface_form]);
            }

            $this->delete();

            return $card;
        });

        return $card;
    }
}
