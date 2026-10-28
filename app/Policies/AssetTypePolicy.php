<?php

namespace App\Policies;

class AssetTypePolicy extends MasterDataPolicy
{
    protected string $viewAbility = 'view-assets';

    protected string $manageAbility = 'manage-master-data';
}
