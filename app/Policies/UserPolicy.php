<?php

namespace App\Policies;

class UserPolicy extends MasterDataPolicy
{
    protected string $viewAbility = 'manage-users';

    protected string $manageAbility = 'manage-users';
}
