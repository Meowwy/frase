<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WordboxController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($id)
    {
        //
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
    public function store()
    {
        $wordbox = Auth::user()->wordboxes()->create([
            'name'        => 'unnamed',
            'description' => '',
            'exam_text'   => '',
        ]);
        return redirect()->route('wordbox.show', ['id' => $wordbox->id]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $cards = Auth::user()->wordboxes()
            ->where('id', $id)
            ->firstOrFail()
            ->cards()
            ->get();

        $wordbox = Auth::user()->wordboxes()->where('id', $id)->first();

        if(!$wordbox){
            return redirect('/');
        }
        return view('wordbox.index', ['cards' => $cards, 'wordboxId' => $id, 'wordbox' => $wordbox]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
