<?php

namespace App\Policies;

use App\Models\PrioritizationRun;
use App\Models\User;

class PrioritizationRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-rankings');
    }

    public function view(User $user, PrioritizationRun $run): bool
    {
        return $user->can('view-rankings')
            && ($run->status === 'completed' || $user->can('run-prioritization'));
    }

    public function create(User $user): bool
    {
        return $user->can('run-prioritization');
    }

    public function update(User $user, PrioritizationRun $run): bool
    {
        return $run->status === 'draft' && $user->can('run-prioritization');
    }

    public function delete(User $user, PrioritizationRun $run): bool
    {
        return $this->update($user, $run);
    }
}
