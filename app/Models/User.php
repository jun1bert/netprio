<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Role assignment is deliberately excluded from mass assignment.
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function hasRole(string ...$codes): bool
    {
        return $this->is_active !== false && $this->role !== null && in_array($this->role->code, $codes, true);
    }

    public function hasPermission(string $ability, array $builtInRoles): bool
    {
        return $this->hasRole(...$builtInRoles) || ($this->is_active !== false && $this->role !== null
            && ! in_array($this->role->code, [Role::ADMINISTRATOR, Role::EVALUATOR, Role::MANAGEMENT], true)
            && in_array($ability, $this->role->permissions ?? [], true));
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'assessed_by');
    }

    public function prioritizationRuns(): HasMany
    {
        return $this->hasMany(PrioritizationRun::class, 'created_by');
    }

    public function pairwiseComparisons(): HasMany
    {
        return $this->hasMany(PairwiseComparison::class, 'created_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
