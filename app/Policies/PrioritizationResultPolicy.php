<?php

namespace App\Policies;

use App\Models\PrioritizationResult;
use App\Models\User;

class PrioritizationResultPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-rankings');
    }

    public function view(User $user, PrioritizationResult $result): bool
    {
        return $result->prioritizationRun !== null && $user->can('view', $result->prioritizationRun);
    }
    // Results are written by the authorized prioritization service, not edited individually.
}
