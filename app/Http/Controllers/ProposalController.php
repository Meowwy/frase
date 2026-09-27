<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeProposalJob;
use App\Models\AI;
use App\Models\Card;
use App\Models\Proposal;
use App\Models\ProposalBaseWord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * CAPTURE: the capture endpoint and staging.
 *
 * Capture writes a proposal and returns immediately — no card, no language picker, no
 * waiting on the AI. Everything else here is the learner acting on what CALL 1 came back
 * with: striking a candidate word, editing the anchor phrase, correcting a wrong language
 * detection, and finally approving (which writes the card and runs CALL 2) or discarding.
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
            // Long enough to accept a pasted sentence, which CALL 1 reduces to a reusable
            // expression frame ("I would like to go ..." => "I would like to ...").
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
     * The staging feed — one list, newest first, across every language.
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
            'pending' => $data['proposals']->contains(
                fn (Proposal $p) => in_array($p->status, [Proposal::STATUS_PENDING, Proposal::STATUS_PROCESSING], true)
            ),
        ]);
    }

    /**
     * Strike (or un-strike) one candidate word, so it never becomes a base word. It is the
     * only per-word control and it never rewrites the Term — the card still teaches the
     * whole phrase, just linked to fewer words.
     */
    public function strike(Request $request, Proposal $proposal, ProposalBaseWord $word)
    {
        $this->authorize('update', $proposal);
        abort_unless($word->proposal_id === $proposal->id, 404);

        $word->update(['struck' => $request->boolean('struck')]);

        return response()->json($this->cardinality($proposal->refresh()));
    }

    /**
     * Save, clear or re-propose a word card's anchor phrase. Clearing is reversible: the
     * block stays as a "+ Add one" state rather than disappearing.
     */
    public function anchor(Request $request, Proposal $proposal)
    {
        $this->authorize('update', $proposal);
        abort_unless($proposal->card_shape === Card::SHAPE_WORD, 422);

        if ($request->boolean('regenerate')) {
            $anchor = AI::suggestAnchor($proposal->term, $proposal->language->name, $proposal->context, $proposal->anchor);

            if (is_null($anchor)) {
                return response()->json(['message' => 'Could not come up with another phrase. Please try again.'], 500);
            }
        } else {
            $data = $request->validate(['anchor' => ['nullable', 'string', 'max:120']]);
            $anchor = $request->filled('anchor') ? trim($data['anchor']) : null;
        }

        $proposal->update(['anchor' => $anchor]);

        return response()->json(['anchor' => $anchor]);
    }

    /**
     * Correct a wrong language detection. The candidate words were extracted (and
     * translated, and tagged) for the language CALL 1 guessed, so they can't simply be
     * re-pointed: the proposal goes back through CALL 1, this time pinned to the language
     * the learner chose rather than detecting one.
     */
    public function language(Request $request, Proposal $proposal)
    {
        $this->authorize('update', $proposal);

        $data = $request->validate(['language_id' => ['required', 'integer']]);

        abort_unless(Auth::user()->languages()->whereKey($data['language_id'])->exists(), 422);

        $proposal->baseWords()->delete();
        $proposal->update([
            'language_id' => $data['language_id'],
            'status' => Proposal::STATUS_PENDING,
        ]);

        AnalyzeProposalJob::dispatch($proposal);

        return response()->json(['reanalyzing' => true]);
    }

    /**
     * Approve: write the card and its base-word links, and pay for CALL 2 at last.
     */
    public function approve(Proposal $proposal)
    {
        $this->authorize('update', $proposal);

        if ($duplicate = $proposal->duplicateCard()) {
            return response()->json([
                'message' => 'You already have a card for "'.$duplicate->term.'".',
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

        return response()->json(['redirect' => '/cards/'.$card->id]);
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
        $proposals = $user->proposals()->with(['baseWords', 'language'])->latest('id')->get();

        return [
            'proposals' => $proposals,
            'targetLanguages' => $user->languages()->orderBy('name')->get(),
            // Resolved for the whole list in one query rather than per chip — see
            // Proposal::presenceIndex() for why that matters on a polled endpoint.
            'alreadyInBase' => Proposal::presenceIndex($proposals),
        ];
    }

    /**
     * How many base words the proposal keeps, and whether that satisfies its shape's rule
     * — so the UI can disable Approve and the last strike rather than failing afterwards.
     */
    private function cardinality(Proposal $proposal): array
    {
        return [
            'kept' => $proposal->keptBaseWords()->count(),
            'max' => Card::MAX_BASE_WORDS,
            'approvable' => $proposal->isApprovable(),
        ];
    }
}
