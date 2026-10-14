<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Frammenti: the daily active-vocabulary drill over the vocabulary base and expression
 * base, in batches of AI-generated fragments. This class owns what lives in PHP — the
 * word-level schedule, the daily session plan and choosing a batch — the same way
 * Learning owns the card SRS. See docs/frammenti.md.
 */
class Frammenti
{
    public const BATCH = 5;

    /** Nouns, and verbs, a language needs in the base before Frammenti opens for it. */
    public const MIN_NOUNS_AND_VERBS = 5;

    public const TIERS = [1 => 'I', 2 => 'II', 3 => 'III'];

    /** A correct answer whose doubled interval reaches this many days promotes the item. */
    public const PROMOTE_AT_DAYS = 8;

    /** The interval, in days, a promoted item starts its new tier at. */
    public const PROMOTED_INTERVAL = 2;

    public const MAX_INTERVAL = 180;

    /** Misses in a row that demote an item at tier II or III. */
    public const MISSES_TO_DEMOTE = 2;

    /** Repetitions a new or missed item owes in today's session, by CEFR level. */
    public const REPS = ['A1' => 3, 'A2' => 3, 'B1' => 2, 'B2' => 2, 'C1' => 1, 'C2' => 1];

    /** Repetitions owed when the language has no level set. */
    public const DEFAULT_REPS = 2;

    /** The most batches one day's session plans. */
    public const MAX_BATCHES = 10;

    /** A repetition never comes within this many fragments of its previous appearance. */
    public const SPACING = 2;

    public const SECONDS_PER_FRAGMENT = 25;

    /** Levels whose fragments are built only from words the learner has proven. */
    private const BEGINNER_LEVELS = ['A1', 'A2'];

    /** Proven words sent as context for tiers I and II. */
    private const CONTEXT_WORDS = 80;

    /** Proven nouns, and verbs, sent as context for a beginner's tier III task. */
    private const SENTENCE_WORDS = 15;

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

    /** The "type:id" key an item goes by in a session's owed map and the on-screen list. */
    public static function key(BaseWord|FixedExpression $item): string
    {
        return self::type($item).':'.$item->id;
    }

    public static function nativeLanguageName(User $user): string
    {
        return optional($user->nativeLanguage)->name ?? $user->native_language ?? 'English';
    }

    /** Words and fixed expressions the learner can produce: tier II and up. */
    public static function activeWords(User $user, ?Language $language = null): int
    {
        $count = fn ($relation) => $relation->where('frammenti_tier', '>=', 2)
            ->when($language, fn ($q) => $q->where('language_id', $language->id))->count();

        return $count($user->baseWords()) + $count($user->fixedExpressions());
    }

    public static function session(User $user, int $languageId): ?FrammentiSession
    {
        return FrammentiSession::where(['user_id' => $user->id, 'language_id' => $languageId, 'date' => $user->today()])->first();
    }

    /**
     * Today's session, planned and stored on first start. Null when there is nothing to
     * plan (nothing due and nothing new): the learner can still do Extra drill.
     */
    public static function startSession(User $user, Language $language): ?FrammentiSession
    {
        if ($session = self::session($user, $language->id)) {
            return $session;
        }

        $plan = self::plan($user, $language);

        return $plan['batches'] ? FrammentiSession::create([
            'user_id' => $user->id,
            'language_id' => $language->id,
            'date' => $user->today(),
            'planned_batches' => $plan['batches'],
            'batches_done' => 0,
            'introduced' => 0,
            'owed' => [],
        ]) : null;
    }

    /**
     * What today holds for a language before it is started: due items, new items and the
     * batches they need. At most MAX_BATCHES; when that cap bites, new items are cut first,
     * then the least overdue due items (dealing takes the most overdue first). What's cut
     * rolls to tomorrow.
     *
     * @return array{due: int, new: int, batches: int, minutes: int}
     */
    public static function plan(User $user, Language $language): array
    {
        $today = $user->today();
        $reps = self::reps($user->levelForLanguage($language));
        $items = self::items($user, $language);
        $capacity = self::MAX_BATCHES * self::BATCH;

        $due = min($items->filter(fn ($item) => self::isDue($item, $today))->count(), $capacity);
        $new = min(self::newPerDay($user, $language), $items->whereNull('frammenti_due_on')->count(), intdiv($capacity - $due, $reps));
        // A new item needs a batch for each of its repetitions.
        $batches = max((int) ceil(($due + $new * $reps) / self::BATCH), $new ? $reps : 0);

        return [
            'due' => $due,
            'new' => $new,
            'batches' => $batches,
            'minutes' => (int) ceil($batches * self::BATCH * self::SECONDS_PER_FRAGMENT / 60),
        ];
    }

    /**
     * Apply one answer to an item's STORED schedule and today's session — never to a tier
     * or attempt the client sent. Only the first attempt of the day moves the schedule:
     *
     * - new or due: it counts — the scheduling rules below, and the repetitions it owes;
     * - otherwise, owing repetitions today: a correct one pays one off, a wrong one nothing;
     * - otherwise it's an early answer and changes nothing.
     *
     * $last marks the last fragment of a batch, which counts the batch done and, at the
     * planned count, finishes the session. Returns today's session, if there is one.
     */
    public static function answer(User $user, BaseWord|FixedExpression $item, bool $correct, bool $last): ?FrammentiSession
    {
        $today = $user->today();
        $session = self::session($user, $item->language_id);
        $key = self::key($item);
        $owed = $session?->owed ?? [];

        if (self::isNew($item) || self::isDue($item, $today)) {
            $reps = self::reps($user->levelForLanguage($item->language));
            $item->frammenti_miss_streak = $correct ? 0 : $item->frammenti_miss_streak + 1;

            if (self::isNew($item)) {
                // Introducing it is what counts, right or wrong; a correct first attempt
                // already was one repetition.
                $item->frammenti_interval = 1;
                $owed[$key] = $correct ? $reps - 1 : $reps;
                if ($session) {
                    $session->introduced++;
                }
            } elseif ($correct) {
                $interval = $item->frammenti_interval * 2;
                // A harder exercise hasn't proven itself yet, so it starts short.
                if ($interval >= self::PROMOTE_AT_DAYS && $item->frammenti_tier < 3) {
                    $item->frammenti_tier++;
                    $interval = self::PROMOTED_INTERVAL;
                }
                $item->frammenti_interval = min($interval, self::MAX_INTERVAL);
                unset($owed[$key]);
            } else {
                $item->frammenti_interval = 1;
                if ($item->frammenti_miss_streak >= self::MISSES_TO_DEMOTE && $item->frammenti_tier > 1) {
                    $item->frammenti_tier--;
                    $item->frammenti_miss_streak = 0;
                }
                $owed[$key] = $reps;
            }

            $item->frammenti_due_on = Carbon::parse($today)->addDays($item->frammenti_interval)->toDateString();
        } elseif ($correct && isset($owed[$key])) {
            $owed[$key]--;
        }

        // All three tiers are production, so every correct answer is a recall.
        if ($correct) {
            $item->last_recalled_at = now();
        }
        $item->save();

        if ($session) {
            $session->owed = array_filter($owed);
            if ($last && ! $session->finished_at && ++$session->batches_done >= $session->planned_batches) {
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
     * During today's planned session the 5 slots take, in order: owed repetitions, due
     * items (most overdue first), new items (while the day's intake lasts and enough
     * planned batches remain for their repetitions), then the items closest to due as
     * early answers. Extra drill — no session, or a finished one — takes only those last.
     * In a language too small for that, the items on screen top the batch up.
     *
     * $onScreen is the batch currently shown, in order. Its items are never dealt as due or
     * new again; an owed one comes back no sooner than SPACING fragments after its slot.
     * One whose answer hasn't arrived yet is planned as if answered correctly.
     *
     * @param  array<int, string>  $onScreen  "type:id" keys
     */
    public static function deal(User $user, Language $language, ?FrammentiSession $session, array $onScreen): ?array
    {
        $today = $user->today();
        $level = $user->levelForLanguage($language);
        $reps = self::reps($level);
        // A base collection: an Eloquent one's except() goes by model id, not by these keys.
        $all = self::items($user, $language)->toBase()->keyBy(fn ($item) => self::key($item));
        $onScreen = array_values(array_filter($onScreen, fn ($key) => $all->has($key)));
        $pool = $all->except($onScreen);
        $position = array_flip($onScreen);

        // "type:id" => the first slot (0-based) the item may take, in priority order.
        $candidates = [];

        if ($session && ! $session->finished_at) {
            $owed = $session->owed;
            foreach ($onScreen as $key) {
                if (self::isNew($all[$key])) {
                    $owed[$key] = $reps - 1;
                } elseif (self::isDue($all[$key], $today)) {
                    unset($owed[$key]);
                }
            }
            foreach (array_keys(array_filter($owed)) as $key) {
                if ($all->has($key)) {
                    $candidates[$key] = isset($position[$key]) ? max(0, $position[$key] - self::SPACING) : 0;
                }
            }

            $candidates += $pool->filter(fn ($item) => self::isDue($item, $today))
                ->sortBy([['frammenti_due_on', 'asc'], ['frammenti_interval', 'asc']])
                ->map(fn () => 0)->all();

            $intake = self::newPerDay($user, $language) - $session->introduced
                - collect($onScreen)->filter(fn ($key) => self::isNew($all[$key]))->count();
            $batchesLeft = $session->planned_batches - $session->batches_done - ($onScreen ? 1 : 0);
            if ($intake > 0 && $batchesLeft >= $reps) {
                $candidates += $pool->filter(fn ($item) => self::isNew($item))
                    ->sortBy([
                        fn ($a, $b) => self::isNounOrVerb($b) <=> self::isNounOrVerb($a),
                        fn ($a, $b) => $b->created_at <=> $a->created_at,
                    ])
                    ->take($intake)->map(fn () => 0)->all();
            }
        }

        $candidates += $pool->filter(fn ($item) => ! self::isNew($item) && ! self::isDue($item, $today))
            ->sortBy('frammenti_due_on')->map(fn () => 0)->all();
        $candidates += array_fill_keys($onScreen, 0);

        // Only the last two on-screen slots hold anything back (to slot 2 and slot 1 at the
        // latest), so laying the batch out by first allowed slot always keeps the spacing.
        $chosen = array_slice($candidates, 0, self::BATCH, true);
        asort($chosen);
        $slots = array_map(fn ($key) => self::slot($all[$key]), array_keys($chosen));

        $types = array_column($slots, 'fragment_type');
        $beginner = in_array($level, self::BEGINNER_LEVELS, true);
        $proven = $all->whereNotNull('last_recalled_at')->shuffle()
            ->sortBy([['frammenti_tier', 'desc'], ['frammenti_interval', 'desc']]);
        $provenWords = $proven->whereInstanceOf(BaseWord::class);
        $forms = fn ($items) => $items->map(fn ($item) => $item instanceof BaseWord ? $item->lemma : $item->form)->values()->all();

        $fragments = AI::generateFragments(
            $slots,
            $language->name,
            self::nativeLanguageName($user),
            $level,
            $beginner && in_array('I', $types, true) ? $forms($provenWords->take(self::CONTEXT_WORDS)) : null,
            in_array('II', $types, true) ? $forms($proven->take(self::CONTEXT_WORDS)) : null,
            $beginner && in_array('III', $types, true) ? $forms($provenWords->where('part_of_speech', 'noun')->take(self::SENTENCE_WORDS)
                ->concat($provenWords->where('part_of_speech', 'verb')->take(self::SENTENCE_WORDS))) : null,
        );

        return $fragments ? array_map(fn ($slot, $fragment) => $slot + $fragment, $slots, $fragments) : null;
    }

    /**
     * Days in a row with a finished planned session in any language. Today doesn't break
     * it before it's over, and one missed day per ISO week is forgiven.
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

    public static function reps(?string $level): int
    {
        return self::REPS[$level] ?? self::DEFAULT_REPS;
    }

    private static function newPerDay(User $user, Language $language): int
    {
        return (int) $user->languages()->whereKey($language->id)->first()?->pivot->frammenti_new_per_day;
    }

    private static function isNew(BaseWord|FixedExpression $item): bool
    {
        return $item->frammenti_due_on === null;
    }

    private static function isDue(BaseWord|FixedExpression $item, string $today): bool
    {
        return $item->frammenti_due_on !== null && $item->frammenti_due_on <= $today;
    }

    private static function isNounOrVerb(BaseWord|FixedExpression $item): bool
    {
        return $item instanceof BaseWord && in_array($item->part_of_speech, ['noun', 'verb'], true);
    }

    /**
     * One slot of the batch, as the AI call takes it: the item's stored tier is its
     * fragment type.
     */
    private static function slot(BaseWord|FixedExpression $item): array
    {
        $isWord = $item instanceof BaseWord;

        return [
            'type' => self::type($item),
            'id' => $item->id,
            'fragment_type' => self::TIERS[$item->frammenti_tier],
            'item' => $isWord ? $item->displayForm() : $item->form,
            'part_of_speech' => $isWord ? $item->part_of_speech : 'fixed expression',
            'item_translation' => $item->translation,
        ];
    }
}
