<?php

namespace App\Policies;

class CriterionPolicy extends MasterDataPolicy
{
    protected string $viewAbility = 'view-criteria';

    protected string $manageAbility = 'manage-criteria';
}
