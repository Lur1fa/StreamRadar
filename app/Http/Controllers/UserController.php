<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\OidcService;
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
    public function index(OidcService $oidc): Response
    {
        return Inertia::render('Users', [
            'users' => User::orderBy('name')->get()->map->toPublicArray()->values(),
            'roles' => User::ROLES,
            'passwordMinLength' => (int) config('security.password_min_length'),
            'sso' => [
                'enabled' => $oidc->isConfigured(),
                'autoProvision' => $oidc->autoProvisionEnabled(),
                'passwordOptional' => $oidc->linksAccountsByEmail(),
            ],
        ]);
    }

    /**
     * Whether signing in through SSO for the first time creates an account.
     */
    public function updateSso(Request $request, OidcService $oidc): RedirectResponse
    {
        $data = $request->validate([
            'auto_provision' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $data['auto_provision'];
        $oidc->setAutoProvision($enabled);

        return back()->with('success', $enabled
            ? 'New people signing in with SSO now get an account automatically.'
            : 'Automatic account creation is off. Only existing accounts can sign in with SSO.');
    }

    public function store(Request $request, OidcService $oidc): RedirectResponse
    {
        // An account without a password can only be used through SSO, so the
        // password may be left out only when SSO will pick the account up.
        $passwordOptional = $oidc->linksAccountsByEmail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(User::ROLES)],
            'password' => [$passwordOptional ? 'nullable' : 'required', 'string', Password::defaults()],
        ]);

        $password = $data['password'] ?? null;

        User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'role' => $data['role'],
            'password' => $password,
            'email_verified_at' => now(),
        ]);

        return back()->with('success', $password === null
            ? "User \"{$data['name']}\" created. They sign in with SSO."
            : "User \"{$data['name']}\" created.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', Rule::in(User::ROLES)],
            'disabled' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', Password::defaults()],
        ]);

        $actor = $request->user();

        return DB::transaction(function () use ($data, $user, $actor) {
            if (array_key_exists('role', $data) && $data['role'] !== $user->role) {
                if ($user->is($actor)) {
                    return back()->with('error', 'You cannot change your own role.');
                }
                if ($user->isLastActiveAdmin()) {
                    return back()->with('error', 'Cannot demote the last administrator.');
                }
                $user->role = $data['role'];
            }

            if (array_key_exists('disabled', $data)) {
                $disable = (bool) $data['disabled'];
                if ($disable && $user->is($actor)) {
                    return back()->with('error', 'You cannot disable your own account.');
                }
                if ($disable && $user->isLastActiveAdmin()) {
                    return back()->with('error', 'Cannot disable the last administrator.');
                }
                $user->disabled_at = $disable ? ($user->disabled_at ?? now()) : null;
                if ($disable) {
                    $user->endAllSessions();
                }
            }

            if (array_key_exists('name', $data)) {
                $user->name = $data['name'];
            }

            if (! empty($data['password'])) {
                $user->password = $data['password'];
                // Password reset by an admin ends every session of that user.
                $user->endAllSessions();
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
        if ($user->isLastActiveAdmin()) {
            return back()->with('error', 'Cannot delete the last administrator.');
        }

        DB::transaction(function () use ($user) {
            $user->endAllSessions();
            $user->delete();
        });

        return back()->with('success', "User \"{$user->name}\" deleted.");
    }
}
