<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeProposalJob;
use App\Models\Card;
use App\Models\KnownWord;
use App\Models\Proposal;
use App\Models\ProposalBaseWord;
use App\Models\ProposalFixedExpression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * CAPTURE: the capture endpoint and staging.
 *
 * Capture writes a proposal and returns immediately — no card, no language picker, no
 * waiting on the AI. Everything else here is the learner acting on what CALL 1 came back
 * with: striking a candidate word as known or a fixed expression, correcting a wrong language detection, editing
 * the Context (or picking a sense, which is the same thing), picking the language of a Term
 * at home in several, marking existing cards to merge
 * away, and finally
 * approving (which writes the card and runs CALL 2) or discarding.
 *
 * See docs/cards.md "Staging" and USERFLOW.md.
 */
class ProposalController extends Controller
{
    /**
     * Capture a term. Shared by the web form and the browser extension
     * (POST /api/addWordAPI), which is why it always answers JSON.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            // Long enough to accept a pasted sentence, which is kept as typed.
            'capturedWord' => ['required', 'string', 'min:2', 'max:120'],
            'context' => ['nullable', 'string', 'min:2', 'max:250'],
        ]);

        $user = Auth::user();

        if ($user->languages()->doesntExist()) {
            return response()->json(['message' => 'Please set up a target language in your settings first.'], 422);
        }

        $proposal = $user->proposals()->create([
            'raw_input' => trim($data['capturedWord']),
            // An untouched context input submits "", which is not null.
            'context' => $request->filled('context') ? trim($data['context']) : null,
            'source' => $request->routeIs('captureApi') ? 'extension' : 'web',
        ]);

        AnalyzeProposalJob::dispatch($proposal);

        return response()->json([
            'proposal_id' => $proposal->id,
            'staged_count' => $user->proposals()->count(),
            'message' => '"'.$proposal->raw_input.'" is in staging.',
        ]);
    }

    /**
     * The staging feed — one list, in the order the terms were captured, across every language.
     */
    public function index()
    {
        return view('staging.index', $this->listData());
    }

    /**
     * The same list as JSON, for the fast path: a freshly captured proposal shows as a
     * skeleton row and the page polls this until nothing is pending any more.
     */
    public function list()
    {
        $data = $this->listData();

        return response()->json([
            'rows' => view('staging._proposals', $data)->render(),
            'count' => $data['proposals']->count(),
            'pending' => $data['proposals']->contains(fn (Proposal $p) => $p->isAwaitingAnalysis()),
        ]);
    }

    /**
     * Strike a new candidate word as known, or tap a known one to un-know it. A known word
     * is remembered across captures (on lemma + part of speech, so every inflected form is
     * covered) and never becomes a base word. It never rewrites the Term — the card still
     * teaches the whole phrase, just linked to fewer words.
     */
    public function known(Request $request, Proposal $proposal, ProposalBaseWord $word)
    {
        $this->authorize('update', $proposal);
        abort_unless($word->proposal_id === $proposal->id, 404);

        $attributes = [
            'user_id' => $proposal->user_id,
            'language_id' => $proposal->language_id,
            'lemma' => $word->lemma,
            'part_of_speech' => $word->part_of_speech,
        ];

        $request->boolean('known')
            ? KnownWord::firstOrCreate($attributes)
            : KnownWord::where($attributes)->delete();

        return response()->noContent();
    }

    /**
     * Strike (or un-strike) one fixed expression so it isn't saved. Unlike a word this is
     * per proposal: a struck fixed expression is never remembered as known.
     */
    public function strikeExpression(Request $request, Proposal $proposal, ProposalFixedExpression $expression)
    {
        $this->authorize('update', $proposal);
        abort_unless($expression->proposal_id === $proposal->id, 404);

        $expression->update(['struck' => $request->boolean('struck')]);

        return response()->noContent();
    }

    /**
     * Correct a wrong language detection, or answer the language picker. The candidate
     * words were extracted (and translated, and tagged) for the language CALL 1 guessed, so
     * they can't simply be re-pointed: the proposal goes back through CALL 1, this time
     * pinned to the language the learner chose rather than detecting one.
     */
    public function language(Request $request, Proposal $proposal)
    {
        $this->authorize('update', $proposal);

        $data = $request->validate(['language_id' => ['required', 'integer']]);

        abort_unless(Auth::user()->languages()->whereKey($data['language_id'])->exists(), 422);

        $proposal->reanalyze(['language_id' => $data['language_id']]);

        return response()->json(['reanalyzing' => true]);
    }

    /**
     * Add, edit or clear a proposal's Context. The Context decides which sense the Term is
     * read in, so the proposal goes back through CALL 1 just as for a language correction.
     * Picking a sense from the sense picker is this same call, with the sense written out
     * as the Context — so the re-run offers no senses and extracts in the chosen one.
     */
    public function context(Request $request, Proposal $proposal)
    {
        $this->authorize('update', $proposal);

        $data = $request->validate(['context' => ['nullable', 'string', 'min:2', 'max:250']]);

        $proposal->reanalyze(['context' => $request->filled('context') ? trim($data['context']) : null]);

        return response()->json(['reanalyzing' => true]);
    }

    /**
     * Try CALL 1 again on a proposal whose analysis failed or stalled.
     */
    public function retry(Proposal $proposal)
    {
        $this->authorize('update', $proposal);

        $proposal->reanalyze();

        return response()->json(['reanalyzing' => true]);
    }

    /**
     * Mark (or unmark) one of the learner's existing cards to be removed when this proposal
     * is approved. Nothing happens to it until then, and discarding the proposal leaves it.
     */
    public function merge(Request $request, Proposal $proposal, Card $card)
    {
        $this->authorize('update', $proposal);
        $this->authorize('delete', $card);
        abort_unless($card->language_id === $proposal->language_id, 422);

        $proposal->markForMerge($card, $request->boolean('merge'));

        return response()->noContent();
    }

    /**
     * Approve: write the card and its base-word links, and pay for CALL 2 at last.
     */
    public function approve(Proposal $proposal)
    {
        $this->authorize('update', $proposal);

        if ($duplicate = $proposal->blockingDuplicates()->first()) {
            return response()->json([
                'message' => 'You already have a card for "'.$duplicate->term.'". Add a Context or merge it into this one.',
                'duplicate_card_id' => $duplicate->id,
            ], 409);
        }

        if (! $proposal->isApprovable()) {
            return response()->json(['message' => 'This proposal is not ready to be approved.'], 422);
        }

        $card = $proposal->approve();

        if (is_null($card)) {
            return response()->json(['message' => 'There was an error while creating the card.'], 500);
        }

        // Staging stays open: the row collapses to a link to the new card.
        return response()->json([
            'url' => '/cards/'.$card->id,
            'term' => $card->term,
            'staged_count' => Auth::user()->proposals()->count(),
        ]);
    }

    /**
     * Discard: the proposal is gone. Nothing is kept — no discard history, no draft state.
     * The undo window is the toast's own, client-side, before this ever fires.
     */
    public function destroy(Proposal $proposal)
    {
        $this->authorize('delete', $proposal);

        $proposal->delete();

        return response()->json(['staged_count' => Auth::user()->proposals()->count()]);
    }

    /**
     * Shared view data for the staging list and its JSON twin.
     */
    private function listData(): array
    {
        $user = Auth::user();
        $proposals = $user->proposals()->with(['baseWords', 'fixedExpressions', 'language'])->oldest('id')->get();

        return [
            'proposals' => $proposals,
            'targetLanguages' => $user->languages()->orderBy('name')->get(),
            // Resolved for the whole list in one query per store rather than per chip — see
            // Proposal::presenceIndex() for why that matters on a polled endpoint. Related
            // cards are read off alreadyInBase, so they add no query of their own.
            'alreadyInBase' => Proposal::presenceIndex($proposals),
            'knownWords' => Proposal::knownIndex($proposals),
            'expressionsInBase' => Proposal::expressionPresenceIndex($proposals),
        ];
    }
}
