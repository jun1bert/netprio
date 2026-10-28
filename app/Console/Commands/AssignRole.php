<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignRole extends Command
{
    protected $signature = 'netprior:assign-role {email : Existing account email} {role : administrator, evaluator, or management}';

    protected $description = 'Assign an institutional role to an existing account and record the change';

    public function handle(): int
    {
        $code = (string) $this->argument('role');
        if (! in_array($code, [Role::ADMINISTRATOR, Role::EVALUATOR, Role::MANAGEMENT], true)) {
            $this->error('Unknown role. Use administrator, evaluator, or management.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($code) {
                // All role changes lock this row first to serialize last-administrator checks.
                $administrator = Role::where('code', Role::ADMINISTRATOR)->lockForUpdate()->first();
                $role = Role::where('code', $code)->first();
                if (! $administrator || ! $role) {
                    throw ValidationException::withMessages(['role' => 'Run php artisan db:seed --class=RoleSeeder first.']);
                }
                $user = User::where('email', $this->argument('email'))->lockForUpdate()->first();
                if (! $user) {
                    throw ValidationException::withMessages(['email' => 'No account exists with that email.']);
                }
                if ($code === Role::ADMINISTRATOR && ! $user->is_active) {
                    throw ValidationException::withMessages(['role' => 'Reactivate the account before assigning the administrator role.']);
                }
                if ($user->role_id === $role->id) {
                    return;
                }
                if ($user->role_id === $administrator->id && $role->id !== $administrator->id
                    && $user->is_active && User::where('role_id', $administrator->id)->where('is_active', true)->count() <= 1) {
                    throw ValidationException::withMessages(['role' => 'Assign another administrator before demoting the last administrator.']);
                }
                $oldRole = $user->role_id;
                $user->role()->associate($role)->save();
                AuditLog::create([
                    'user_id' => null, 'action' => 'user.role_assigned', 'entity_type' => User::class,
                    'entity_id' => $user->id, 'description' => 'Institutional role assigned through the server console.',
                    'metadata' => ['source' => 'console', 'previous_role_id' => $oldRole, 'role_id' => $role->id, 'role_code' => $role->code],
                ]);
            });
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Role assignment saved.');

        return self::SUCCESS;
    }
}
