<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public function record(string $action, Model $entity, array $metadata = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => auth()->id(), 'action' => $action,
            'entity_type' => $entity::class, 'entity_id' => is_numeric($entity->getKey()) ? $entity->getKey() : null,
            'description' => str_replace('.', ' ', $action),
            'metadata' => ['actor_name' => auth()->user()?->name, 'entity_key' => $entity->getKey()] + $metadata,
        ]);
    }
}
