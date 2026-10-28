<?php

namespace App\Http\Controllers;

use App\Http\Requests\ManageUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('manage-users');
        $f = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'active' => ['nullable', 'in:0,1']]);
        $query = User::with('role');
        if ($f['search'] ?? null) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$f['search'].'%')->orWhere('email', 'like', '%'.$f['search'].'%'));
        }
        if (isset($f['active'])) {
            $query->where('is_active', $f['active']);
        }

        return view('admin.users', ['users' => $query->orderBy('name')->paginate(20)->withQueryString()]);
    }

    public function create()
    {
        Gate::authorize('manage-users');

        return $this->form(new User(['is_active' => true]));
    }

    public function edit(User $user)
    {
        Gate::authorize('manage-users');

        return $this->form($user);
    }

    private function form(User $user)
    {
        return view('admin.user-form', ['account' => $user, 'roles' => Role::orderBy('name')->pluck('name', 'id')]);
    }

    public function store(ManageUserRequest $request, AccountService $service)
    {
        $service->save($request->user(), $request->validated());

        return redirect()->route('users.index')->with('status', 'Account created.');
    }

    public function update(ManageUserRequest $request, User $user, AccountService $service)
    {
        $service->save($request->user(), $request->validated(), $user);

        return redirect()->route('users.index')->with('status', 'Account updated.');
    }
}
