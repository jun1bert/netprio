<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentScore extends Model
{
    protected $fillable = [
        'assessment_id',
        'criterion_id',
        'raw_value',
        'raw_unit',
        'score',
        'remarks',
        'criterion_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'raw_value' => 'float',
            'score' => 'float',
            'criterion_snapshot' => 'array',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(Criterion::class);
    }
}
