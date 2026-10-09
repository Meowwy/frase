<?php

namespace App\Models;

use Illuminate\Support\Collection;

/**
 * Frammenti: unscheduled practice over the vocabulary base and expression base, in
 * batches of AI-generated fragments. This class owns the two things that live in PHP —
 * the progress rules and choosing a batch — the same way Learning owns the SRS.
 * See docs/frammenti.md.
 */
class Frammenti
{
    public const BATCH = 5;

    /**
     * The lowest tier an item starts at or can be demoted to. Tier I (recognition) is
     * discontinued: nothing deals or generates it any more.
     */
    public const FIRST_TIER = 2;

    /** Nouns, and verbs, a language needs in the base before Frammenti opens for it. */
    public const MIN_NOUNS_AND_VERBS = 5;

    /** The tier past III. It is dealt as III. */
    public const MASTERED = 4;

    /** Correct (or wrong) answers in a row that move an item up (or down) a tier. */
    public const STREAK = 2;

    /** Rest after a correct answer, in hours, by the tier answered at. */
    public const REST_HOURS = [1 => 4, 2 => 24, 3 => 72];

    /** Rest after each correct answer while mastered, in days; the last repeats. */
    public const MASTERED_REST_DAYS = [7, 14, 30, 60];

    /** Below this CEFR level fragments are built from the learner's own base. */
    private const BASE_BUILT_LEVELS = ['A1', 'A2'];

    /** How many of the learner's lemmas a below-B1 batch is built from. */
    private const BASE_SAMPLE = 80;

    /**
     * Apply one answer to an item's STORED progress — never to a tier the client sent.
     * Only a correct answer at tier II or above stamps last recall: recall means the
     * learner produced the word, and tier I only asks them to recognise it.
     */
    public static function answer(BaseWord|FixedExpression $item, bool $correct): void
    {
        $tier = $item->frammenti_tier;

        if ($correct && $tier === self::MASTERED) {
            $days = self::MASTERED_REST_DAYS[min($item->frammenti_correct_streak, count(self::MASTERED_REST_DAYS) - 1)];
            $item->frammenti_rest_until = now()->addDays($days);
            $item->frammenti_correct_streak++;
        } elseif ($correct) {
            $item->frammenti_wrong_streak = 0;
            if (++$item->frammenti_correct_streak === self::STREAK) {
                $item->frammenti_tier++;
                $item->frammenti_correct_streak = 0;
            }
            $item->frammenti_rest_until = now()->addHours(self::REST_HOURS[$tier]);
        } elseif ($tier === self::MASTERED) {
            $item->frammenti_tier = 3;
            $item->frammenti_correct_streak = 0;
            $item->frammenti_wrong_streak = 0;
            $item->frammenti_rest_until = null;
        } else {
            $item->frammenti_correct_streak = 0;
            $item->frammenti_rest_until = null;
            if (++$item->frammenti_wrong_streak === self::STREAK) {
                $item->frammenti_tier = max(self::FIRST_TIER, $tier - 1);
                $item->frammenti_wrong_streak = 0;
            }
        }

        if ($correct && $tier >= 2) {
            $item->last_recalled_at = now();
        }

        $item->save();
    }

    /**
     * Every base word and fixed expression the learner has in one language.
     */
    public static function items(User $user, Language $language): Collection
    {
        return $user->baseWords()->with('language')->where('language_id', $language->id)->get()
            ->concat($user->fixedExpressions()->where('language_id', $language->id)->get());
    }

    /**
     * Whether Frammenti is open for a language: at least 5 nouns and 5 verbs in the base,
     * ignoring rest. Below that the page shows its empty state and no AI call is made.
     */
    public static function enough(User $user, Language $language): bool
    {
        $counts = $user->baseWords()->where('language_id', $language->id)
            ->whereIn('part_of_speech', ['noun', 'verb'])
            ->selectRaw('part_of_speech, count(*) as total')->groupBy('part_of_speech')
            ->pluck('total', 'part_of_speech');

        return ($counts['noun'] ?? 0) >= self::MIN_NOUNS_AND_VERBS && ($counts['verb'] ?? 0) >= self::MIN_NOUNS_AND_VERBS;
    }

    public static function type(BaseWord|FixedExpression $item): string
    {
        return $item instanceof BaseWord ? 'base_word' : 'fixed_expression';
    }

    /**
     * Choose and generate one batch, or null when the AI call fails. The caller has
     * already checked enough().
     *
     * Each slot takes a random tier among the ready items, then a random ready item at
     * it; whatever ready items can't fill comes from the resting ones that wake soonest,
     * and — only in a language too small to fill a batch without them — from the items
     * the client excluded (the batch currently on screen).
     *
     * @param  array<int, string>  $exclude  "type:id" keys
     */
    public static function deal(User $user, Language $language, array $exclude): ?array
    {
        $all = self::items($user, $language);
        [$excluded, $pool] = $all->partition(fn ($item) => in_array(self::type($item).':'.$item->id, $exclude, true));
        [$ready, $resting] = $pool->partition(fn ($item) => ! $item->frammenti_rest_until?->isFuture());

        $chosen = [];
        $byTier = $ready->shuffle()->groupBy(fn ($item) => min($item->frammenti_tier, 3))->all();

        while (count($chosen) < self::BATCH && $byTier) {
            $tier = array_rand($byTier);
            $chosen[] = $byTier[$tier]->pop();
            if ($byTier[$tier]->isEmpty()) {
                unset($byTier[$tier]);
            }
        }

        $topUp = $resting->sortBy('frammenti_rest_until')->concat($excluded->shuffle());
        $chosen = array_merge($chosen, $topUp->take(self::BATCH - count($chosen))->all());

        $level = $user->levelForLanguage($language);
        $baseBuilt = in_array($level, self::BASE_BUILT_LEVELS, true);
        $slots = array_map(self::slot(...), $chosen);
        $hasTierThree = in_array('III', array_column($slots, 'fragment_type'), true);

        $fragments = AI::generateFragments(
            $slots,
            $language->name,
            optional($user->nativeLanguage)->name ?? $user->native_language ?? 'English',
            $level,
            $baseBuilt ? $all->whereInstanceOf(BaseWord::class)->shuffle()->take(self::BASE_SAMPLE)->pluck('lemma')->all() : null,
            // A tier III fragment is written out whole, so it may only use words the
            // learner already handles at tier III.
            $hasTierThree ? $all->filter(fn ($item) => $item->frammenti_tier >= 3)->shuffle()->take(self::BASE_SAMPLE)
                ->map(fn ($item) => $item instanceof BaseWord ? $item->lemma : $item->form)->values()->all() : null,
        );

        if (! $fragments) {
            return null;
        }

        return array_map(fn ($slot, $fragment) => [
            'type' => $slot['type'],
            'id' => $slot['id'],
            'fragment_type' => $slot['fragment_type'],
            'item' => $slot['item'],
            'part_of_speech' => $slot['part_of_speech'],
            'fragment' => $fragment['fragment'],
            'translation' => $fragment['translation'],
            'accepted' => $fragment['accepted'],
        ], $slots, $fragments);
    }

    /**
     * One slot of the batch, as the AI call takes it: II below tier III, III from it
     * (mastered is dealt as III).
     */
    private static function slot(BaseWord|FixedExpression $item): array
    {
        $isWord = $item instanceof BaseWord;

        return [
            'type' => self::type($item),
            'id' => $item->id,
            'fragment_type' => $item->frammenti_tier >= 3 ? 'III' : 'II',
            'item' => $isWord ? $item->displayForm() : $item->form,
            'part_of_speech' => $isWord ? $item->part_of_speech : 'fixed expression',
            'translation' => $item->translation,
        ];
    }
}
