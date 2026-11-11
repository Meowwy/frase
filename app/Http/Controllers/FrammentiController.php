<?php

namespace App\Http\Controllers;

use App\Models\AI;
use App\Models\ArticleDrill;
use App\Models\BaseWord;
use App\Models\FixedExpression;
use App\Models\Frammenti;
use App\Models\Language;
use App\Models\Learning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * LEARN: Frammenti, the daily active-vocabulary drill over the vocabulary base and
 * expression base. The page plays batches client-side; this controller plans sessions,
 * deals the batches, grades tier III sentences and saves each answer as it's given. It also
 * serves and saves the article drill shown on the same page. See docs/frammenti.md.
 */
class FrammentiController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $languages = Frammenti::languages($user);
        $language = $languages->firstWhere('id', (int) $request->query('language_id'))
            ?? $languages->firstWhere('id', $user->currentSaveLanguage()?->id)
            ?? $languages->first();
        $session = $language ? Frammenti::session($user, $language->id) : null;

        return view('frammenti.index', [
            'targetLanguages' => $languages,
            'language' => $language,
            'enough' => $language && Frammenti::enough($user, $language),
            'requirement' => $language ? Frammenti::requirement($user, $language) : '',
            'session' => $session,
            'plan' => $language && ! $session ? Frammenti::plan($user, $language) : null,
            'activeWords' => $language ? Frammenti::activeWords($user, $language) : 0,
            'streak' => Frammenti::streak($user),
            'articles' => $language && ArticleDrill::available($language) ? ArticleDrill::due($user, $language)->count() : null,
            'classicDue' => $language ? Frammenti::dueItems($user, $language)->count() : 0,
        ]);
    }

    /**
     * An article-drill session, played in the flashcard view and saved here, not on
     * /saveLearning: it must never touch a card's schedule.
     */
    public function articles(Request $request)
    {
        $language = $this->targetLanguage((int) $request->query('language_id'));
        $cards = ArticleDrill::session(Auth::user(), $language);

        return view('learning.index', [
            'cards' => 'let cards = '.json_encode($cards).';',
            'cardCount' => count($cards),
            'action' => route('frammenti.articles', ['language_id' => $language->id]),
            'answerStyle' => 'cards',
        ]);
    }

    /**
     * Persist article-drill answers: `results`, `{id, result}` per noun, its first answer,
     * sent as each noun leaves the deck (AJAX, 204) and once more, empty, to leave (a redirect
     * back to Frammenti). Another learner's id is skipped, as on /saveLearning.
     */
    public function saveArticles(Request $request)
    {
        $user = Auth::user();
        $language = $this->targetLanguage((int) $request->input('language_id'));
        $results = json_decode($request->input('results'), true) ?? [];
        $nouns = $user->baseWords()->whereIn('id', array_column($results, 'id'))->get()->keyBy('id');

        foreach ($results as $r) {
            if ($noun = $nouns->get($r['id'])) {
                ArticleDrill::answer($user, $noun, $r['result'] === 1);
            }
        }

        return $request->ajax()
            ? response()->noContent()
            : redirect()->route('frammenti', ['language_id' => $language->id]);
    }

    /**
     * Classic: the due words and fixed expressions as flashcards in the card-review view,
     * answered by flipping (`style=cards`) or by typing first (`style=writing`). The
     * alternative to a Frammenti session, on the same schedule. See docs/frammenti.md "Classic".
     */
    public function classic(Request $request)
    {
        $user = Auth::user();
        $language = $this->targetLanguage((int) $request->query('language_id'));

        abort_unless(Frammenti::enough($user, $language), 422);

        $cards = Frammenti::classicEntries($user, $language);

        return view('learning.index', [
            'cards' => 'let cards = '.json_encode($cards).';',
            'cardCount' => count($cards),
            'action' => route('frammenti.classic', ['language_id' => $language->id]),
            'answerStyle' => Learning::answerStyle($request->query('style')),
        ]);
    }

    /**
     * Save Classic answers — each item's first answer, sent as it leaves the deck — on the
     * Frammenti schedule. Keys are "type:id"; another learner's item is skipped.
     */
    public function saveClassic(Request $request)
    {
        $user = Auth::user();
        $language = $this->targetLanguage((int) $request->input('language_id'));

        foreach (json_decode($request->input('results'), true) ?? [] as $r) {
            [$type, $id] = array_pad(explode(':', (string) ($r['id'] ?? '')), 2, 0);
            $relation = match ($type) {
                'base_word' => $user->baseWords(),
                'fixed_expression' => $user->fixedExpressions(),
                default => null,
            };

            if ($item = $relation?->where('language_id', $language->id)->find((int) $id)) {
                Frammenti::answerClassic($user, $item, ($r['result'] ?? 0) === 1);
            }
        }

        return $request->ajax()
            ? response()->noContent()
            : redirect()->route('frammenti', ['language_id' => $language->id]);
    }

    /**
     * Deal one batch of the unfinished session, or of a new one planned and stored now.
     */
    public function batch(Request $request)
    {
        $data = $request->validate([
            'language_id' => 'required|integer',
            'on_screen' => 'array',
            'on_screen.*.type' => 'required|in:base_word,fixed_expression',
            'on_screen.*.id' => 'required|integer',
        ]);
        $user = Auth::user();
        $language = $this->targetLanguage($data['language_id']);

        if (! Frammenti::enough($user, $language)) {
            return response()->json(['message' => 'Frammenti needs '.Frammenti::requirement($user, $language).' in this language.'], 422);
        }

        $session = Frammenti::startSession($user, $language);

        if (! $session) {
            return response()->json(['message' => 'Nothing is due in this language.'], 422);
        }

        $onScreen = array_map(fn ($e) => $e['type'].':'.$e['id'], $data['on_screen'] ?? []);
        $fragments = Frammenti::deal($user, $language, $onScreen);

        if (! $fragments) {
            return response()->json(['message' => 'Could not generate a batch.'], 503);
        }

        return response()->json([
            'fragments' => $fragments,
            'planned_batches' => $session->planned_batches,
            'batches_done' => $session->batches_done,
        ]);
    }

    /**
     * Apply one answer, as it's given. The client is trusted with right/wrong, as on
     * /saveLearning; everything else comes from the item's stored state.
     */
    public function answer(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:base_word,fixed_expression',
            'id' => 'required|integer',
            'result' => 'required|boolean',
            'last' => 'boolean',
        ]);
        $user = Auth::user();
        $item = $this->item($data['type'], $data['id']);
        $before = $item->frammenti_level;

        $session = Frammenti::answer($user, $item, $data['result'], $data['last'] ?? false);

        return response()->json([
            'level_before' => $before,
            'level_after' => $item->frammenti_level,
            'batches_done' => $session?->batches_done,
            // Only on the answer that finishes the session; `due` says whether another
            // one can start.
            'recap' => $session?->wasChanged('finished_at') ? [
                'streak' => Frammenti::streak($user),
                'active_words' => Frammenti::activeWords($user),
                'due' => Frammenti::plan($user, $item->language)['due'],
            ] : null,
        ]);
    }

    /**
     * Grade a tier III sentence, telling the item's linked synonyms apart from a miss. A 503
     * makes the client fall back to self-grading.
     */
    public function evaluate(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:base_word,fixed_expression',
            'id' => 'required|integer',
            'task' => 'required|string|max:500',
            'sentence' => 'required|string|max:1000',
        ]);
        $user = Auth::user();
        $item = $this->item($data['type'], $data['id']);
        $isWord = $item instanceof BaseWord;

        $evaluation = AI::evaluateSentence(
            $isWord ? $item->displayForm() : $item->form,
            $item->translation,
            $isWord ? $item->part_of_speech : 'fixed expression',
            Frammenti::synonyms($item->synonyms()),
            $data['task'],
            $data['sentence'],
            $item->language->name,
            Frammenti::nativeLanguageName($user),
            $user->levelForLanguage($item->language),
        );

        return $evaluation
            ? response()->json($evaluation)
            : response()->json(['message' => 'Could not evaluate the sentence.'], 503);
    }

    /**
     * One of the learner's own items; another learner's id is a 404.
     */
    private function item(string $type, int $id): BaseWord|FixedExpression
    {
        $relation = $type === 'base_word' ? Auth::user()->baseWords() : Auth::user()->fixedExpressions();

        return $relation->findOrFail($id);
    }

    private function targetLanguage(int $id): Language
    {
        return Frammenti::languages(Auth::user())->firstWhere('id', $id) ?? abort(403, 'Unauthorized action.');
    }
}
