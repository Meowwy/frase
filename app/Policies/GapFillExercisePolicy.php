<?php

namespace App\Policies;

use App\Models\GapFillExercise;
use App\Models\User;

class GapFillExercisePolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, GapFillExercise $exercise): bool
    {
        return $user->id === $exercise->wordbox->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, GapFillExercise $exercise): bool
    {
        return $user->id === $exercise->wordbox->user_id;
    }
}
