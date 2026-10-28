<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrioritizationRun extends Model
{
    protected $fillable = [
        'name',
        'created_by',
        'consistency_index',
        'consistency_ratio',
        'status',
        'completed_at',
        'decision_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'consistency_index' => 'float',
            'consistency_ratio' => 'float',
            'decision_snapshot' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function prioritizationResults(): HasMany
    {
        return $this->hasMany(PrioritizationResult::class);
    }
}
