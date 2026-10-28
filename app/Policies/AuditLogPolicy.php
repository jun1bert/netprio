<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-audit-logs');
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $user->can('view-audit-logs');
    }
    // No user-facing create, update or delete permission for audit records.
}
