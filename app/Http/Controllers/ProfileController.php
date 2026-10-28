<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        try {
            DB::transaction(function () use ($user) {
                $administrator = Role::where('code', Role::ADMINISTRATOR)->lockForUpdate()->first();
                $account = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if ($administrator && $account->role_id === $administrator->id
                    && User::where('role_id', $administrator->id)->where('is_active', true)->count() <= 1) {
                    throw ValidationException::withMessages([
                        'account' => 'Assign another administrator before deleting the last administrator account.',
                    ])->errorBag('userDeletion');
                }
                if ($account->assessments()->exists() || $account->prioritizationRuns()->exists()
                    || $account->pairwiseComparisons()->exists()) {
                    throw ValidationException::withMessages([
                        'account' => 'This account has decision history and cannot be deleted. Contact your institution administrator.',
                    ])->errorBag('userDeletion');
                }
                $account->delete();
            });
        } catch (QueryException $exception) {
            // A concurrent insertion of decision history must also preserve the session and account.
            if (in_array($exception->errorInfo[1] ?? null, [1451, 787], true)) {
                throw ValidationException::withMessages([
                    'account' => 'This account has decision history and cannot be deleted. Contact your institution administrator.',
                ])->errorBag('userDeletion');
            }
            throw $exception;
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
