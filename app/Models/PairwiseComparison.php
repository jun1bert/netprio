<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PairwiseComparison extends Model
{
    protected $fillable = [
        'criterion_a_id',
        'criterion_b_id',
        'comparison_value',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'comparison_value' => 'float',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function criterionA(): BelongsTo
    {
        return $this->belongsTo(Criterion::class, 'criterion_a_id');
    }

    public function criterionB(): BelongsTo
    {
        return $this->belongsTo(Criterion::class, 'criterion_b_id');
    }
}
