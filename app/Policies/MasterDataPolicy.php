<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class MasterDataPolicy
{
    protected string $viewAbility = 'view-assets';

    protected string $manageAbility = 'manage-master-data';

    public function viewAny(User $user): bool
    {
        return $user->can($this->viewAbility);
    }

    public function view(User $user, Model $model): bool
    {
        return $user->can($this->viewAbility);
    }

    public function create(User $user): bool
    {
        return $user->can($this->manageAbility);
    }

    public function update(User $user, Model $model): bool
    {
        return $user->can($this->manageAbility);
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->can($this->manageAbility);
    }
}
