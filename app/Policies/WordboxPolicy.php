<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Wordbox;

class WordboxPolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Wordbox $wordbox): bool
    {
        return $user->id === $wordbox->user_id;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Wordbox $wordbox): bool
    {
        return $user->id === $wordbox->user_id;
    }
}
