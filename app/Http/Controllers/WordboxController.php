<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsBaseEntries;
use App\Http\Requests\StoreWordboxRequest;
use App\Models\BaseWord;
use App\Models\Wordbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * A wordbox: a named group of base words in one language, made to feed gap-fill. See
 * docs/wordboxes-themes-tags.md.
 */
class WordboxController extends Controller
{
    use ListsBaseEntries;

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreWordboxRequest $request)
    {
        $user = Auth::user();

        // Multi-language users pick the wordbox language in the modal; otherwise fall back to
        // the current save language. Always validate the chosen language belongs to the user.
        $language = null;
        if ($request->filled('language_id')) {
            $language = $user->languages()->whereKey($request->input('language_id'))->first();
        }
        $language ??= $user->currentSaveLanguage();

        if (! $language) {
            return redirect('/profile/edit');
        }

        $nextPosition = $user->wordboxes()
            ->where('language_id', $language->id)
            ->max('position') + 1;

        $wordbox = $user->wordboxes()->create($request->safe()->only(['name']) + [
            // `description` and `exam_text` are both NOT NULL with no default. An empty
            // description textarea arrives as null (ConvertEmptyStringsToNull), which the
            // column rejects — coalesce to '' rather than let the insert 500.
            'description' => $request->input('description') ?? '',
            'exam_text' => '',
            'language_id' => $language->id,
            'position' => $nextPosition,
        ]);

        return redirect()->route('wordbox.show', ['id' => $wordbox->id]);
    }

    /**
     * The wordbox's words in the vocabulary base's own table (ListsBaseEntries), beside its
     * gap-fill exercises and the checklist a new one is generated from.
     */
    public function show(Request $request, string $id)
    {
        $user = Auth::user();
        $wordbox = $user->wordboxes()->with('language')->findOrFail($id);

        return $this->baseTable($request, $user->baseWords()->whereHas('wordboxes', fn ($q) => $q->whereKey($wordbox->id)), null, $wordbox->language_id, 'wordbox.index', [
            'wordbox' => $wordbox,
            'activeLanguageId' => $wordbox->language_id,
            'words' => $wordbox->baseWords()->with('language')->orderBy('lemma')->get(),
        ]);
    }

    public function edit(string $id)
    {
        $wordbox = Auth::user()->wordboxes()->findOrFail($id);

        return view('wordbox.edit', [
            'wordbox' => $wordbox,
            'words' => $wordbox->baseWords()->with('language')->orderBy('lemma')->get()->map($this->row(...)),
        ]);
    }

    /**
     * The edit page's search: the learner's base words of the wordbox's language whose lemma
     * matches.
     */
    public function search(Request $request, string $id)
    {
        $request->validate([
            'searchTerm' => ['required', 'string', 'min:2'],
        ]);

        $wordbox = Auth::user()->wordboxes()->findOrFail($id);

        $words = Auth::user()->baseWords()->with('language')
            ->where('language_id', $wordbox->language_id)
            ->where('lemma', 'like', '%'.$request->input('searchTerm').'%')
            ->orderBy('lemma')
            ->limit(10)
            ->get();

        return response()->json(['words' => $words->map($this->row(...))]);
    }

    /**
     * Update name.
     */
    public function updateName(Request $request, string $id)
    {
        // Validate the input
        $request->validate([
            'name' => 'required|string|max:50',
        ]);

        // Find the wordbox that belongs to the authenticated user
        $wordbox = Auth::user()->wordboxes()->where('id', $id)->firstOrFail();

        // Update the name
        $wordbox->update([
            'name' => $request->input('name'),
        ]);

        // Redirect back with success message
        return redirect()->back()->with('success', 'Wordbox name updated successfully.');
    }

    /**
     * Save the edit page's list: the wordbox holds exactly these words afterwards. sync()
     * leaves the rows of the words that stay untouched.
     */
    public function update(Request $request, string $id)
    {
        $wordbox = Auth::user()->wordboxes()->findOrFail($id);

        $wordbox->baseWords()->sync($this->wordIds($request, $wordbox, ['array']));

        return redirect()->route('wordbox.show', ['id' => $id]);
    }

    /** /base's bulk "Add to wordbox": checked words already in the box stay as they are. */
    public function addWords(Request $request, string $id)
    {
        $wordbox = Auth::user()->wordboxes()->findOrFail($id);
        $ids = $this->wordIds($request, $wordbox, ['required', 'array']);

        $wordbox->baseWords()->syncWithoutDetaching($ids);

        return response()->json(['added' => count($ids), 'wordbox' => $wordbox->name]);
    }

    /** The wordbox page's bulk "Remove from wordbox": the words stay in the base. */
    public function removeWords(Request $request, string $id)
    {
        $wordbox = Auth::user()->wordboxes()->findOrFail($id);
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        $wordbox->baseWords()->detach($data['ids']);

        return response()->noContent();
    }

    /**
     * The request's base word ids. Each has to be one of the learner's own words in the
     * wordbox's language — anything else fails validation.
     */
    private function wordIds(Request $request, Wordbox $wordbox, array $rules): array
    {
        $data = $request->validate([
            'ids' => $rules,
            'ids.*' => ['integer', 'distinct', Rule::exists('base_words', 'id')->where(fn ($q) => $q
                ->where('user_id', $wordbox->user_id)
                ->where('language_id', $wordbox->language_id)
                ->where('deleted', false))],
        ]);

        return $data['ids'] ?? [];
    }

    /** One word as the edit page lists it. */
    private function row(BaseWord $word): array
    {
        return ['id' => $word->id, 'form' => $word->displayForm(), 'translation' => $word->translation];
    }
}
