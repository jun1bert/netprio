<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrioritizationResult extends Model
{
    protected $fillable = [
        'prioritization_run_id',
        'network_asset_id',
        'assessment_id',
        'final_score',
        'rank',
        'priority_level',
        'recommendation',
        'asset_snapshot',
        'score_breakdown',
    ];

    protected function casts(): array
    {
        return [
            'asset_snapshot' => 'array',
            'final_score' => 'float',
            'rank' => 'integer',
            'score_breakdown' => 'array',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function networkAsset(): BelongsTo
    {
        return $this->belongsTo(NetworkAsset::class);
    }

    public function prioritizationRun(): BelongsTo
    {
        return $this->belongsTo(PrioritizationRun::class);
    }
}
