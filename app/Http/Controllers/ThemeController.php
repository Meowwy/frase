<?php

namespace App\Http\Controllers;

use App\Models\AI;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ThemeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $themes = Theme::where('user_id', Auth::id())
            ->get(['id', 'name']); // Get only the id and name columns

// Format the result as an array of associative arrays
        $themesArray = $themes->map(function ($theme) {
            return [
                'id' => $theme->id,
                'name' => $theme->name,
            ];
        })->toArray();


        //return view('user.settings', ['themes' => $uniqueTags->name]);
        return view('user.themes', ['themes' => $themesArray]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $updatedThemesString = $request->input('themes');
        $themes = json_decode($updatedThemesString, true);
        $themesFromDatabase = Theme::where('user_id', Auth::id())
            ->get(['id', 'name']); // Get only the id and name columns

        $themesArray = $themesFromDatabase->map(function ($theme) {
            return [
                'id' => $theme->id,
                'name' => $theme->name,
            ];
        })->toArray();

        foreach ($themesArray as $theme) {
            // Extract the IDs from the $themes array
            $themeIds = array_column($themes, 'id');

            // Check if the current theme's ID is in the list of IDs
            if (!in_array($theme['id'], $themeIds)) {
                $themeToDelete = Theme::find($theme['id']);
                $themeToDelete->delete();
            }
        }

        if (json_last_error() === JSON_ERROR_NONE) {
            foreach ($themes as $theme) {
                // Extract the id and name from each theme object in the array
                $themeId = $theme['id'];
                $themeName = $theme['name'];

                if ($themeId) {
                    // If the id is set, find the theme in the database and update its name
                    $existingTheme = Theme::find($themeId);
                    if ($existingTheme->user_id === Auth::id()) {
                        $existingTheme->name = $themeName;
                        $existingTheme->save();
                    }
                } else {
                    // If the id is not set, create a new theme in the database
                    Auth::user()->themes()->create([
                        'name' => $themeName,
                    ]);

                }
            }
        } else {
            // Handle JSON decode error
            dd('JSON decoding failed: ' . json_last_error_msg());
        }

        return redirect('/profile');
    }

    public function generate(){
        $phrases = Auth::user()->cards()
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->pluck('phrase');

// Convert the collection of phrases into a semicolon-separated string
        $phraseString = $phrases->implode('; ');
        $themes = AI::generateThemes($phraseString, Auth::user()->target_language);

        dd($themes);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Theme $theme)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Theme $theme)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Theme $theme)
    {
        //
    }
}
