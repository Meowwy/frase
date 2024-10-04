<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class RegisteredUserController extends Controller
{
    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('auth.register');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'username' => ['required', 'string', 'max:255', 'min:3'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'confirmed', Password::min(6)],
            'targetLanguage' => ['required', 'string', 'different:nativeLanguage'],
            'nativeLanguage' => ['required', 'string'],
            'code' => ['required', 'string', 'in:delina']
        ]);

        $user = User::create([
            'username' => request('username'),
            'email' => request('email'),
            'password' => Hash::make(request('password')),
            'target_language' => request('targetLanguage'),
            'native_language' => request('nativeLanguage'),
            'currency_amount' => 100,
        ]);

        Auth::login($user);

        return redirect('/');
    }
}
