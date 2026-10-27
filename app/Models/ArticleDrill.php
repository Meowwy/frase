<?php

namespace App\Models;

use App\Support\LanguageGuideline;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The article drill on /frammenti: the learner's nouns as flip cards, the bare lemma on the
 * front and the display form ("ett hus") on the back, on a short schedule of its own that
 * touches neither the noun's Frammenti schedule nor any card. See docs/frammenti.md
 * "Article drill".
 */
class ArticleDrill
{
    /** The most nouns one session serves. */
    public const SESSION = 20;

    /** A noun at this level is learned and never due again. */
    public const LEARNED = 3;

    /** Days until due again, by the level a correct answer reaches below LEARNED. */
    private const INTERVALS = [1 => 3, 2 => 6];

    /**
     * Whether the language has a drill: its guideline gives nouns a gender, which is what
     * their article follows from.
     */
    public static function available(Language $language): bool
    {
        return filled(self::genders($language));
    }

    /** The learner's nouns in one language that have a gender, learned ones included. */
    public static function nouns(User $user, Language $language): HasMany
    {
        return $user->baseWords()->where('language_id', $language->id)->where('part_of_speech', 'noun')
            ->whereIn('grammar_attributes->gender', self::genders($language));
    }

    /** Nouns never answered or whose due date has arrived, learned ones excluded. */
    public static function due(User $user, Language $language): HasMany
    {
        return self::nouns($user, $language)->where('article_level', '<', self::LEARNED)
            ->where(fn ($q) => $q->whereNull('article_due_on')->orWhere('article_due_on', '<=', $user->today()));
    }

    /**
     * The flip cards of one session: due nouns, the ones in most cards first, at most
     * SESSION.
     */
    public static function session(User $user, Language $language): array
    {
        return self::due($user, $language)->with('language')
            ->withCount('cards')->orderByDesc('cards_count')->orderBy('id')
            ->take(self::SESSION)->get()
            ->map(fn (BaseWord $noun) => ['id' => $noun->id, 'front' => $noun->lemma, 'back' => $noun->displayForm(), 'hint' => '', 'wordbox' => ''])
            ->all();
    }

    /**
     * Right climbs a level, due in 3 and then 6 days, and the third in a row is learned;
     * wrong drops to level 0, due today.
     */
    public static function answer(User $user, BaseWord $noun, bool $correct): void
    {
        $noun->article_level = $correct ? $noun->article_level + 1 : 0;
        $noun->article_due_on = match (true) {
            ! $correct => $user->today(),
            $noun->article_level === self::LEARNED => null,
            default => Carbon::parse($user->today())->addDays(self::INTERVALS[$noun->article_level])->toDateString(),
        };
        $noun->save();
    }

    /** @return array<int, string> */
    private static function genders(Language $language): array
    {
        return LanguageGuideline::for($language->code)?->attributesFor('noun')['gender']['values'] ?? [];
    }
}
