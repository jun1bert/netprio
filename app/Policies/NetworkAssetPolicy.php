<?php

namespace App\Policies;

class NetworkAssetPolicy extends MasterDataPolicy
{
    protected string $viewAbility = 'view-assets';

    protected string $manageAbility = 'manage-assets';
}
