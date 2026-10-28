<?php

namespace App\Policies;

class LocationPolicy extends MasterDataPolicy
{
    protected string $viewAbility = 'view-assets';

    protected string $manageAbility = 'manage-master-data';
}
