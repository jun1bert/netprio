<?php

namespace App\Policies;

use App\Models\AssessmentScore;
use App\Models\User;

class AssessmentScorePolicy
{
    public function view(User $user, AssessmentScore $score): bool
    {
        return $score->assessment !== null && $user->can('view', $score->assessment);
    }

    // New score rows must be authorized against their parent assessment via update.
    public function update(User $user, AssessmentScore $score): bool
    {
        return $score->assessment !== null && $user->can('update', $score->assessment);
    }

    public function delete(User $user, AssessmentScore $score): bool
    {
        return $this->update($user, $score);
    }
}
