<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    public const ADMINISTRATOR = 'administrator';

    public const EVALUATOR = 'evaluator';

    public const MANAGEMENT = 'management';

    protected $fillable = [
        'code',
        'name',
        'description',
        'permissions',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }
}
