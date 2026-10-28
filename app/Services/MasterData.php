<?php

namespace App\Services;

use App\Models\AssetType;
use App\Models\Criterion;
use App\Models\Location;

class MasterData
{
    public static function definition(string $kind): array
    {
        return match ($kind) {
            'locations' => ['model' => Location::class, 'title' => 'Locations'],
            'asset-types' => ['model' => AssetType::class, 'title' => 'Asset types'],
            'criteria' => ['model' => Criterion::class, 'title' => 'Decision criteria'],
            default => abort(404),
        };
    }
}
