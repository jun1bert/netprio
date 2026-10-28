<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoleManagementController extends Controller
{
    public function index()
    {
        Gate::authorize('manage-roles');

        return view('admin.roles', ['roles' => Role::withCount('users')->orderBy('name')->paginate(20)]);
    }

    public function create()
    {
        Gate::authorize('manage-roles');

        return $this->form(new Role);
    }

    public function edit(Role $role)
    {
        Gate::authorize('manage-roles');

        return $this->form($role);
    }

    private function form(Role $role)
    {
        return view('admin.role-form', ['role' => $role, 'abilities' => array_keys(config('netprior.permissions'))]);
    }

    private function save(Request $request, ?Role $role = null)
    {
        Gate::authorize('manage-roles');
        $values = $request->validate(['code' => ['required', 'alpha_dash', 'max:50', Rule::unique('roles')->ignore($role)], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'], 'permissions' => ['sometimes', 'array'], 'permissions.*' => ['string', Rule::in(array_keys(config('netprior.permissions')))]]);
        DB::transaction(function () use ($values, $role) {
            Role::where('code', Role::ADMINISTRATOR)->lockForUpdate()->first();
            $record = $role ? Role::lockForUpdate()->findOrFail($role->id) : new Role;
            $builtIn = in_array($record->code, [Role::ADMINISTRATOR, Role::EVALUATOR, Role::MANAGEMENT], true);
            if ($record->exists && $values['code'] !== $record->code) {
                throw ValidationException::withMessages(['code' => 'Role codes are immutable. Create a new role instead.']);
            }
            $record->fill($values);
            $record->permissions = $builtIn ? null : array_values(array_unique($values['permissions'] ?? []));
            $record->save();
            app(AuditService::class)->record('role.saved', $record, ['permissions' => $record->permissions]);
        });

        return redirect()->route('roles.index')->with('status', 'Role saved.');
    }

    public function store(Request $request)
    {
        return $this->save($request);
    }

    public function update(Request $request, Role $role)
    {
        return $this->save($request, $role);
    }

    public function destroy(Role $role)
    {
        Gate::authorize('manage-roles');

        return DB::transaction(function () use ($role) {
            $record = Role::lockForUpdate()->findOrFail($role->id);
            if (in_array($record->code, [Role::ADMINISTRATOR, Role::EVALUATOR, Role::MANAGEMENT], true) || $record->users()->exists()) {
                return back()->withErrors(['role' => 'Built-in roles and roles assigned to users cannot be deleted.']);
            }
            $record->delete();
            app(AuditService::class)->record('role.deleted', $record);

            return redirect()->route('roles.index')->with('status', 'Role deleted.');
        });
    }
}
