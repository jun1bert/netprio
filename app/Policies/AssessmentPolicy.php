<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\User;

class AssessmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-assessments');
    }

    public function view(User $user, Assessment $assessment): bool
    {
        return $user->can('view-assessments');
    }

    public function create(User $user): bool
    {
        return $user->can('create-assessments');
    }

    public function update(User $user, Assessment $assessment): bool
    {
        return $assessment->status === 'draft' && (
            $user->can('manage-assessments')
            || ($user->can('create-assessments') && $assessment->assessed_by === $user->id)
        );
    }

    public function delete(User $user, Assessment $assessment): bool
    {
        return $this->update($user, $assessment);
    }
}
