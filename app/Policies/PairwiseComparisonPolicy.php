<?php

namespace App\Policies;

class PairwiseComparisonPolicy extends MasterDataPolicy
{
    protected string $viewAbility = 'view-criteria';

    protected string $manageAbility = 'manage-comparisons';
}
