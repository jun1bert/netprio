<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Criterion extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'direction',
        'weight',
        'display_order',
        'is_active',
        'rubric',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'is_active' => 'boolean',
            'weight' => 'float',
            'rubric' => 'array',
        ];
    }

    public function assessmentScores(): HasMany
    {
        return $this->hasMany(AssessmentScore::class);
    }

    public function comparisonsAsA(): HasMany
    {
        return $this->hasMany(PairwiseComparison::class, 'criterion_a_id');
    }

    public function comparisonsAsB(): HasMany
    {
        return $this->hasMany(PairwiseComparison::class, 'criterion_b_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
