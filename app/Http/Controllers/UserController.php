<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administrator-only account management. Routes are behind `auth` + `can:admin`.
 */
class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Users', [
            'users' => User::orderBy('name')->get()->map->toPublicArray()->values(),
            'roles' => User::ROLES,
            'passwordMinLength' => (int) config('security.password_min_length', 12),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(User::ROLES)],
            'password' => ['required', 'string', Password::min((int) config('security.password_min_length', 12))],
        ]);

        User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'role' => $data['role'],
            'password' => $data['password'],
            'email_verified_at' => now(),
        ]);

        return back()->with('success', "User \"{$data['name']}\" created.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', Rule::in(User::ROLES)],
            'disabled' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', Password::min((int) config('security.password_min_length', 12))],
        ]);

        $actor = $request->user();

        return DB::transaction(function () use ($data, $user, $actor) {
            if (array_key_exists('role', $data) && $data['role'] !== $user->role) {
                if ($user->is($actor)) {
                    return back()->with('error', 'You cannot change your own role.');
                }
                if ($user->isAdmin() && $this->isLastActiveAdmin($user)) {
                    return back()->with('error', 'Cannot demote the last administrator.');
                }
                $user->role = $data['role'];
            }

            if (array_key_exists('disabled', $data)) {
                $disable = (bool) $data['disabled'];
                if ($disable && $user->is($actor)) {
                    return back()->with('error', 'You cannot disable your own account.');
                }
                if ($disable && $user->isAdmin() && $this->isLastActiveAdmin($user)) {
                    return back()->with('error', 'Cannot disable the last administrator.');
                }
                $user->disabled_at = $disable ? ($user->disabled_at ?? now()) : null;
                if ($disable) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
            }

            if (array_key_exists('name', $data)) {
                $user->name = $data['name'];
            }

            if (! empty($data['password'])) {
                $user->password = $data['password'];
                // Password reset by an admin ends every session of that user.
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            $user->save();

            return back()->with('success', "User \"{$user->name}\" updated.");
        });
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot delete your own account.');
        }
        if ($user->isAdmin() && $this->isLastActiveAdmin($user)) {
            return back()->with('error', 'Cannot delete the last administrator.');
        }

        DB::transaction(function () use ($user) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();
        });

        return back()->with('success', "User \"{$user->name}\" deleted.");
    }

    private function isLastActiveAdmin(User $user): bool
    {
        return User::admins()->active()->where('id', '!=', $user->id)->doesntExist();
    }
}
