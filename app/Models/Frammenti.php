<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Frammenti: the daily active-vocabulary drill over the vocabulary base and expression
 * base, in batches of AI-generated fragments. This class owns what lives in PHP — the
 * level-based schedule, the session plan and choosing a batch — the same way Learning
 * owns the card SRS. See docs/frammenti.md.
 */
class Frammenti
{
    public const BATCH = 5;

    /** Nouns, and verbs, a language needs in the base before Frammenti opens for it. */
    public const MIN_NOUNS_AND_VERBS = 5;

    public const TIERS = [1 => 'I', 2 => 'II', 3 => 'III'];

    public const MAX_LEVEL = 8;

    /** The most batches one session plans. */
    public const MAX_BATCHES = 10;

    public const SECONDS_PER_FRAGMENT = 25;

    /** Levels whose fragments are built only from words the learner has proven; no level counts as a beginner. */
    private const BEGINNER_LEVELS = [null, 'A1', 'A2'];

    /** Proven words sent as context to a beginner's batch. */
    private const CONTEXT_WORDS = 80;

    /** Card Terms sent with an item to lock its meaning. */
    private const TERMS_PER_ITEM = 2;

    /**
     * The learner's target languages — never their native one, even when they collect
     * native-language vocabulary: "translating" your own language into itself is meaningless.
     */
    public static function languages(User $user): Collection
    {
        return $user->languages()->orderBy('name')
            ->when($user->native_language_id, fn ($q, $native) => $q->whereKeyNot($native))
            ->get();
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
     * Whether Frammenti is open for a language: at least 5 nouns and 5 verbs in the base.
     * Below that the page shows its empty state and no AI call is made.
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

    /** The "type:id" key an item goes by in the on-screen list. */
    public static function key(BaseWord|FixedExpression $item): string
    {
        return self::type($item).':'.$item->id;
    }

    public static function nativeLanguageName(User $user): string
    {
        return optional($user->nativeLanguage)->name ?? $user->native_language ?? 'English';
    }

    /** The tier a level is tested at: 0–2 I, 3–5 II, 6–8 III. */
    public static function tier(int $level): int
    {
        return intdiv($level, 3) + 1;
    }

    /** Words and fixed expressions the learner can produce: tier II and up. */
    public static function activeWords(User $user, ?Language $language = null): int
    {
        $count = fn ($relation) => $relation->where('frammenti_level', '>=', 3)
            ->when($language, fn ($q) => $q->where('language_id', $language->id))->count();

        return $count($user->baseWords()) + $count($user->fixedExpressions());
    }

    /** Today's unfinished session; there is at most one at a time. */
    public static function session(User $user, int $languageId): ?FrammentiSession
    {
        return FrammentiSession::where(['user_id' => $user->id, 'language_id' => $languageId, 'date' => $user->today()])
            ->whereNull('finished_at')->first();
    }

    /**
     * Today's unfinished session, or a new one planned and stored now. Null when nothing
     * is due.
     */
    public static function startSession(User $user, Language $language): ?FrammentiSession
    {
        if ($session = self::session($user, $language->id)) {
            return $session;
        }

        $batches = self::plan($user, $language)['batches'];

        return $batches ? FrammentiSession::create([
            'user_id' => $user->id,
            'language_id' => $language->id,
            'date' => $user->today(),
            'planned_batches' => $batches,
            'batches_done' => 0,
        ]) : null;
    }

    /**
     * What a session started now would hold: the items due and the batches they need, at
     * most MAX_BATCHES. What doesn't fit stays due for the next session.
     *
     * @return array{due: int, batches: int, minutes: int}
     */
    public static function plan(User $user, Language $language): array
    {
        $today = $user->today();
        $due = self::items($user, $language)->filter(fn ($item) => self::isDue($item, $today))->count();
        $batches = min((int) ceil($due / self::BATCH), self::MAX_BATCHES);

        return [
            'due' => $due,
            'batches' => $batches,
            'minutes' => (int) ceil($batches * self::BATCH * self::SECONDS_PER_FRAGMENT / 60),
        ];
    }

    /**
     * Apply one answer to an item's STORED schedule — never to a level the client sent.
     * Only a due item's answer counts: right climbs one level and comes back in
     * 2^(level − 1) days, wrong drops to level 0 and stays due today. Any other answer
     * (filler) is early and changes nothing.
     *
     * $last marks the last fragment of a batch, which counts the batch done and, at the
     * planned count, finishes the session. Returns the session the answer was given in,
     * if there is one.
     */
    public static function answer(User $user, BaseWord|FixedExpression $item, bool $correct, bool $last): ?FrammentiSession
    {
        $today = $user->today();

        if (self::isDue($item, $today)) {
            $item->frammenti_level = $correct ? min($item->frammenti_level + 1, self::MAX_LEVEL) : 0;
            $item->frammenti_due_on = $correct
                ? Carbon::parse($today)->addDays(2 ** ($item->frammenti_level - 1))->toDateString()
                : $today;
        }

        // All three tiers are production, so every correct answer is a recall.
        if ($correct) {
            $item->last_recalled_at = now();
        }
        $item->save();

        $session = self::session($user, $item->language_id);
        if ($session && $last) {
            if (++$session->batches_done >= $session->planned_batches) {
                $session->finished_at = now();
            }
            $session->save();
        }

        return $session;
    }

    /**
     * Choose and generate one batch, or null when the AI call fails. The caller has
     * already checked enough().
     *
     * The 5 slots take due items at random; when fewer are due, the items closest to due
     * fill the rest as early answers. $onScreen is the batch currently shown, and its
     * items are never dealt: a miss on screen comes back in a later batch. An item's linked
     * synonyms are skipped once it is dealt, since either could answer the other's
     * fragment. An open language has at least 10 items, so 5 are left unless skipped
     * synonyms leave fewer, which only makes a shorter batch.
     *
     * @param  array<int, string>  $onScreen  "type:id" keys
     */
    public static function deal(User $user, Language $language, array $onScreen): ?array
    {
        $today = $user->today();
        $level = $user->levelForLanguage($language);
        // A base collection: an Eloquent one's except() goes by model id, not by these keys.
        $all = self::items($user, $language)->toBase()->keyBy(fn ($item) => self::key($item));
        [$due, $notDue] = $all->except($onScreen)->partition(fn ($item) => self::isDue($item, $today));
        $synonyms = EntrySynonym::of($all);

        $slots = [];
        $dealt = [];
        $skip = [];
        foreach ($due->shuffle()->concat($notDue->sortBy('frammenti_due_on')) as $item) {
            if (count($slots) === self::BATCH) {
                break;
            }
            if (isset($skip[$item->entryKey()])) {
                continue;
            }

            $itemSynonyms = $synonyms->get($item->entryKey(), collect());
            $slots[] = self::slot($item, $itemSynonyms);
            $dealt[] = $item;
            foreach ($itemSynonyms as $synonym) {
                $skip[$synonym->entryKey()] = true;
            }
        }

        // A beginner's fragments are built from words they have already produced twice.
        $context = in_array($level, self::BEGINNER_LEVELS, true)
            ? $all->where('frammenti_level', '>=', 2)->shuffle()->take(self::CONTEXT_WORDS)
                ->map(fn ($item) => $item instanceof BaseWord ? $item->lemma : $item->form)->values()->all()
            : null;

        // The Terms go to the AI only: the page gets the slots without them.
        $withTerms = array_map(fn ($slot, $item) => $slot + ['terms' => self::terms($item)], $slots, $dealt);
        $fragments = AI::generateFragments($withTerms, $language->name, self::nativeLanguageName($user), $level, $context);

        return $fragments ? array_map(fn ($slot, $fragment) => $slot + $fragment, $slots, $fragments) : null;
    }

    /**
     * The Terms of the item's most recent cards, at most TERMS_PER_ITEM, that hold more than
     * the item itself: a card whose Term is the item alone (any case, bare lemma or display
     * form) says nothing about its sense that the item doesn't already.
     *
     * @return array<int, string>
     */
    private static function terms(BaseWord|FixedExpression $item): array
    {
        $own = array_map(mb_strtolower(...), array_filter([$item instanceof BaseWord ? $item->lemma : null, $item->displayForm()]));

        return $item->cards()->orderByDesc('cards.created_at')->orderByDesc('cards.id')->pluck('cards.term')
            ->map(fn ($term) => trim($term))
            ->reject(fn ($term) => in_array(mb_strtolower($term), $own, true))
            ->take(self::TERMS_PER_ITEM)->values()->all();
    }

    /**
     * Days in a row with a finished session in any language. Today doesn't break it
     * before it's over, and one missed day per ISO week is forgiven.
     */
    public static function streak(User $user): int
    {
        $days = array_flip(FrammentiSession::where('user_id', $user->id)->whereNotNull('finished_at')->pluck('date')->all());
        if (! $days) {
            return 0;
        }

        $first = min(array_keys($days));
        $date = Carbon::parse($user->today());
        if (! isset($days[$date->toDateString()])) {
            $date->subDay();
        }

        $streak = 0;
        $frozen = [];
        for (; $date->toDateString() >= $first; $date->subDay()) {
            if (isset($days[$date->toDateString()])) {
                $streak++;
            } elseif (isset($frozen[$date->format('o-W')])) {
                break;
            } else {
                $frozen[$date->format('o-W')] = true;
            }
        }

        return $streak;
    }

    /** Due: never answered (no due date yet) or its due date has arrived. */
    private static function isDue(BaseWord|FixedExpression $item, string $today): bool
    {
        return $item->frammenti_due_on === null || $item->frammenti_due_on <= $today;
    }

    /**
     * An item's linked synonyms as the AI calls and the page take them: each one's form and
     * translation, never its own level or schedule.
     *
     * @return array<int, array{form: string, translation: string}>
     */
    public static function synonyms(Collection $synonyms): array
    {
        return $synonyms->map(fn ($synonym) => ['form' => $synonym->displayForm(), 'translation' => $synonym->translation])->values()->all();
    }

    /**
     * One slot of the batch, as the AI call takes it: the item's level sets its fragment
     * type.
     */
    private static function slot(BaseWord|FixedExpression $item, Collection $synonyms): array
    {
        $isWord = $item instanceof BaseWord;

        return [
            'type' => self::type($item),
            'id' => $item->id,
            'fragment_type' => self::TIERS[self::tier($item->frammenti_level)],
            'item' => $isWord ? $item->displayForm() : $item->form,
            'part_of_speech' => $isWord ? $item->part_of_speech : 'fixed expression',
            'item_translation' => $item->translation,
            'synonyms' => self::synonyms($synonyms),
        ];
    }
}
