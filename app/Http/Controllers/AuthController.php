<?php

namespace App\Http\Controllers;

use App\Services\AuthEmailCode;
use App\Support\AdminMenu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request, AuthEmailCode $codes): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ], (bool) ($credentials['remember'] ?? false))) {
            return back()->withErrors([
                'email' => 'Invalid credentials.',
            ]);
        }

        $request->session()->regenerate();

        $user = $request->user();
        if ($user->email_verified_at === null) {
            $codes->send($user->email, AuthEmailCode::PURPOSE_EMAIL_VERIFY);

            return redirect()
                ->route('email.verify')
                ->with('status', 'Мы отправили код подтверждения на '.$user->email);
        }

        return redirect()->intended(AdminMenu::homeUrl($user));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
