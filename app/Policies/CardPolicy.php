<?php

namespace App\Policies;

use App\Models\Card;
use App\Models\User;

class CardPolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Card $card): bool
    {
        return $user->cards()->where('id', $card->id)->exists();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Card $card): bool
    {
        return $user->cards()->where('id', $card->id)->exists();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Card $card): bool
    {
        return $user->cards()->where('id', $card->id)->exists();
    }
}
