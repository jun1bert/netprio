<?php

namespace Tests\Concerns;

use App\Models\AssetType;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;

trait BuildsNetpriorRecords
{
    protected function institutionUser(string $code = 'administrator'): User
    {
        $user = User::factory()->create();
        $user->role()->associate(Role::firstOrCreate(['code' => $code], ['name' => ucfirst($code)]))->save();

        return $user;
    }

    protected function assetValues(): array
    {
        return ['asset_code' => 'SW-001', 'name' => 'Core switch', 'status' => 'active',
            'asset_type_id' => AssetType::firstOrCreate(['name' => 'Switch'])->id,
            'location_id' => Location::firstOrCreate(['name' => 'Data center'])->id];
    }
}
