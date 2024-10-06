<?php

namespace App\Http\Controllers;

use App\Models\Tag;
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

        // Format the result as an array of associative arrays


        return view('user.profile', ['themes' => $themes]);
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
        return view('user.edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        // Step 1: Validate the request data
        $validatedData = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'target_language' => ['required','string', 'max:50'],
            'native_language' => ['required','string', 'max:50'],
        ]);

        // Step 2: Find the user by ID
        $user = Auth::user();

        // Step 3: Update the user's data with validated input
        $user->update($validatedData);

        // Step 4: Return a response
        return redirect("/profile");
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
