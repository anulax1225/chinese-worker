<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class SettingsController extends Controller
{
    /**
     * Show the consolidated settings page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $tokens = $user->tokens()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at,
                'created_at' => $token->created_at,
            ]);

        return Inertia::render('Settings/Index', [
            'user' => $user,
            'tokens' => $tokens,
            'twoFactorEnabled' => ! is_null($user->two_factor_secret),
            'twoFactorConfirmed' => ! is_null($user->two_factor_confirmed_at),
        ]);
    }

    /**
     * Show the profile settings page.
     */
    public function profile(Request $request): Response
    {
        return Inertia::render('Settings/Profile', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile.
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,'.$request->user()->id],
        ]);

        $request->user()->update($validated);

        return redirect()->route('settings.profile')
            ->with('success', 'Profile updated successfully.');
    }

    /**
     * Show the password settings page.
     */
    public function password(): Response
    {
        return Inertia::render('Settings/Password');
    }

    /**
     * Update the user's password.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return redirect()->route('settings.password')
            ->with('success', 'Password updated successfully.');
    }

    /**
     * Show the API tokens page.
     */
    public function tokens(Request $request): Response
    {
        $tokens = $request->user()->tokens()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at,
                'created_at' => $token->created_at,
            ]);

        return Inertia::render('Settings/Tokens', [
            'tokens' => $tokens,
        ]);
    }

    /**
     * Create a new API token.
     */
    public function createToken(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string'],
        ]);

        $abilities = $validated['abilities'] ?? ['*'];
        $token = $request->user()->createToken($validated['name'], $abilities);

        return redirect()->route('settings.tokens')
            ->with('success', 'API token created successfully.')
            ->with('token', $token->plainTextToken);
    }

    /**
     * Delete an API token.
     */
    public function deleteToken(Request $request, int $token): RedirectResponse
    {
        $deleted = $request->user()->tokens()
            ->where('id', $token)
            ->delete();

        if (! $deleted) {
            return redirect()->route('settings.tokens')
                ->with('error', 'Token not found.');
        }

        return redirect()->route('settings.tokens')
            ->with('success', 'API token deleted successfully.');
    }

    /**
     * Show the two-factor authentication settings page.
     */
    public function twoFactor(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/TwoFactor', [
            'twoFactorEnabled' => ! is_null($user->two_factor_secret),
            'twoFactorConfirmed' => ! is_null($user->two_factor_confirmed_at),
        ]);
    }
}
