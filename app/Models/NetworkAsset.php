<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NetworkAsset extends Model
{
    protected $fillable = [
        'asset_type_id',
        'location_id',
        'asset_code',
        'name',
        'manufacturer',
        'model',
        'serial_number',
        'ip_address',
        'mac_address',
        'installation_date',
        'warranty_expiry',
        'acquisition_cost',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
            'acquisition_cost' => 'decimal:2',
            'installation_date' => 'date',
            'warranty_expiry' => 'date',
        ];
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function latestCompletedAssessment(): HasOne
    {
        // Filter inside the aggregate so a newer draft cannot hide a completed assessment.
        return $this->hasOne(Assessment::class)->ofMany([
            'assessment_date' => 'max',
            'id' => 'max',
        ], fn (Builder $query) => $query->completed());
    }

    public function assetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function prioritizationResults(): HasMany
    {
        return $this->hasMany(PrioritizationResult::class);
    }
}
