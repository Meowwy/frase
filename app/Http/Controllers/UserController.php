<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Models\Theme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $themes = Theme::where('user_id', Auth::id())
            ->get(['id', 'name']); // Get only the id and name columns

        // A short preview (up to 5) of the user's wordboxes for the profile page.
        $user = Auth::user();
        $wordboxes = $user->wordboxes()
            ->orderBy('position')
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'language_id']);
        $wordboxCount = $user->wordboxes()->count();
        $languages = $user->languages()->get(['languages.id', 'flag']);

        return view('user.profile', [
            'themes' => $themes,
            'wordboxes' => $wordboxes,
            'wordboxCount' => $wordboxCount,
            'languages' => $languages,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit()
    {
        $user = Auth::user();

        // How many saved terms the user has per language (drives the "Hide" prompt).
        $termCounts = $user->cards()
            ->selectRaw('language_id, COUNT(*) as total')
            ->groupBy('language_id')
            ->pluck('total', 'language_id');

        // Current per-language proficiency: [language_id => "B1", ...].
        $selectedLevels = $user->languages()
            ->pluck('language_user.users_level', 'languages.id')
            ->all();

        return view('user.edit', [
            'languages' => Language::orderBy('name')->get(),
            'selectedTargetIds' => $user->languages()->pluck('languages.id')->all(),
            'selectedLevels' => $selectedLevels,
            'proficiencyLevels' => config('proficiency.levels'),
            'proficiencyNames' => config('proficiency.names'),
            'defaultLevel' => config('proficiency.default'),
            'nativeLanguageId' => $user->native_language_id,
            'termCounts' => $termCounts,
        ]);
    }

    /**
     * Show the drag-and-drop wordbox ordering page (per language).
     */
    public function wordboxesOrder()
    {
        $user = Auth::user();

        $languages = $user->languages()->orderBy('name')->get();
        $wordboxesByLanguage = $user->wordboxes()
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'name', 'language_id', 'position'])
            ->groupBy('language_id');

        return view('user.wordboxes-order', [
            'languages' => $languages,
            'wordboxesByLanguage' => $wordboxesByLanguage,
        ]);
    }

    /**
     * Persist the new wordbox order. Expects `order[languageId] = [wordboxId, ...]`.
     */
    public function updateWordboxesOrder(Request $request)
    {
        $validated = $request->validate([
            'order' => ['array'],
            'order.*' => ['array'],
            'order.*.*' => ['integer'],
        ]);

        $user = Auth::user();
        $owned = $user->wordboxes()->pluck('id')->flip();

        foreach (($validated['order'] ?? []) as $ids) {
            $position = 0;
            foreach ($ids as $id) {
                if ($owned->has($id)) {
                    $user->wordboxes()->whereKey($id)->update(['position' => $position++]);
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        $allowedLevels = array_keys(config('proficiency.levels'));
        $defaultLevel = config('proficiency.default');

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'native_language_id' => ['required', 'integer', 'exists:languages,id'],
            'target_language_ids' => ['required', 'array', 'max:5'],
            'target_language_ids.*' => ['integer', 'exists:languages,id'],
            'target_language_levels' => ['array'],
            'target_language_levels.*' => ['in:'.implode(',', $allowedLevels)],
        ]);

        $user = Auth::user();
        $user->username = $validated['username'];
        $user->native_language_id = $validated['native_language_id'];
        $user->save();

        // Sync the up-to-5 target-language set, attaching each language's proficiency level.
        $levels = $validated['target_language_levels'] ?? [];
        $syncData = [];
        foreach ($validated['target_language_ids'] as $languageId) {
            $syncData[$languageId] = [
                'users_level' => $levels[$languageId] ?? $defaultLevel,
            ];
        }
        $user->languages()->sync($syncData);

        // Keep the active (default save) language valid.
        if (! in_array($user->active_language_id, $validated['target_language_ids'])) {
            $user->active_language_id = $validated['target_language_ids'][0] ?? null;
            $user->save();
            session()->forget(['capture_language_id', 'capture_wordbox_id']);
        }

        // Adopt any language-less content (e.g. from before languages existed) into the
        // chosen language, but only when it is unambiguous (a single target language).
        if (count($validated['target_language_ids']) === 1) {
            $languageId = $validated['target_language_ids'][0];
            $user->cards()->whereNull('language_id')->update(['language_id' => $languageId]);
            $user->wordboxes()->whereNull('language_id')->update(['language_id' => $languageId]);
            $user->themes()->whereNull('language_id')->update(['language_id' => $languageId]);
        }

        return redirect('/profile');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
