<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * One captured Term awaiting approval in staging, with everything CALL 1 proposes for it:
 * the Term the card will be built around and its candidate base words.
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

    protected $guarded = [];

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

    /**
     * The candidates that would actually become base-word links, i.e. everything not
     * struck. Striking is the only per-word control there is.
     */
    public function keptBaseWords(): Collection
    {
        return $this->baseWords->reject->struck->values();
    }

    /**
     * Whether Approve may fire: once CALL 1 has landed. How many base words are kept never
     * matters — a card may link none of them, or many. The control disables itself on
     * this rather than failing after the fact.
     */
    public function isApprovable(): bool
    {
        return $this->status === self::STATUS_COMPLETED && (bool) $this->language_id;
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
        $candidates = $proposals->flatMap(fn (self $proposal) => $proposal->baseWords);
        $languageIds = $proposals->pluck('language_id')->filter()->unique();

        if ($candidates->isEmpty() || $languageIds->isEmpty()) {
            return collect();
        }

        return BaseWord::whereIn('user_id', $proposals->pluck('user_id')->unique())
            ->whereIn('language_id', $languageIds)
            ->whereIn('lemma', $candidates->pluck('lemma')->unique())
            ->whereIn('part_of_speech', $candidates->pluck('part_of_speech')->unique())
            // The notice expands to the cards the word is already used in.
            ->with('cards:id,term')
            ->get()
            ->keyBy(fn (BaseWord $word) => $word->language_id.'|'.$word->lemma.'|'.$word->part_of_speech);
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
     * Write the card, its base-word links and the base entries they need, then run CALL 2
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

        $card = DB::transaction(function () use ($user, $language, $content) {
            $card = Card::persist($user, $language, $this->term, $this->context, $content);

            foreach ($this->keptBaseWords() as $candidate) {
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

            $this->delete();

            return $card;
        });

        return $card;
    }
}
