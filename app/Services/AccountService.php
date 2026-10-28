<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AccountService
{
    public function save(User $actor, array $values, ?User $target = null): User
    {
        Gate::forUser($actor)->authorize('manage-users');

        return DB::transaction(function () use ($actor, $values, $target) {
            $administrator = Role::where('code', Role::ADMINISTRATOR)->lockForUpdate()->first();
            $account = $target ? User::lockForUpdate()->findOrFail($target->id) : new User;
            $roleId = isset($values['role_id']) ? (int) $values['role_id'] : null;
            $active = (bool) $values['is_active'];
            if ($account->exists && $account->id === $actor->id && ($roleId !== $account->role_id || ! $active)) {
                throw ValidationException::withMessages(['role_id' => 'Use another administrator account to change your own role or deactivate your account.']);
            }
            if ($account->exists && $administrator && $account->role_id === $administrator->id && $account->is_active
                && (! $active || $roleId !== $administrator->id)
                && User::where('role_id', $administrator->id)->where('is_active', true)->count() <= 1) {
                throw ValidationException::withMessages(['role_id' => 'Keep at least one active administrator account.']);
            }
            $before = $account->only(['name', 'email', 'role_id', 'is_active']);
            $account->fill(['name' => $values['name'], 'email' => $values['email']]);
            if ($account->isDirty('email')) {
                $account->email_verified_at = null;
            }
            $account->role_id = $roleId;
            $account->is_active = $active;
            if ($values['password'] ?? null) {
                $account->password = $values['password'];
                $account->remember_token = null;
            }
            if (! $active) {
                $account->remember_token = null;
            }
            $account->save();
            if ($target && (! $active || ($values['password'] ?? null)) && config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $account->id)->delete();
            }
            app(AuditService::class)->record($target ? 'user.updated' : 'user.created', $account, ['before' => $before, 'after' => $account->only(['name', 'email', 'role_id', 'is_active'])]);

            return $account;
        });
    }
}
