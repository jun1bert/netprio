<?php

namespace App\Policies;

class RolePolicy extends MasterDataPolicy
{
    protected string $viewAbility = 'manage-roles';

    protected string $manageAbility = 'manage-roles';
}
