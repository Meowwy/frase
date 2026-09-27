<?php

namespace App\Policies;

use App\Models\Proposal;
use App\Models\User;

class ProposalPolicy
{
    /**
     * A proposal belongs to exactly one learner; nobody else may see or act on it.
     */
    public function view(User $user, Proposal $proposal): bool
    {
        return $user->id === $proposal->user_id;
    }

    public function update(User $user, Proposal $proposal): bool
    {
        return $user->id === $proposal->user_id;
    }

    public function delete(User $user, Proposal $proposal): bool
    {
        return $user->id === $proposal->user_id;
    }
}
