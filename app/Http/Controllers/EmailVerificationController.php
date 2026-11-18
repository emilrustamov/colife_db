<?php

namespace App\Http\Controllers;

use App\Services\AuthEmailCode;
use App\Support\AdminMenu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationController extends Controller
{
    public function show(Request $request, AuthEmailCode $codes): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return redirect()->to(AdminMenu::homeUrl($user));
        }

        return Inertia::render('Auth/VerifyEmail', [
            'email' => $user->email,
            'ttlMinutes' => $codes->ttlMinutes(),
        ]);
    }

    public function store(Request $request, AuthEmailCode $codes): RedirectResponse
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return redirect()->to(AdminMenu::homeUrl($user));
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        if (! $codes->verify($user->email, AuthEmailCode::PURPOSE_EMAIL_VERIFY, $validated['code'])) {
            return back()->withErrors([
                'code' => 'Неверный или просроченный код.',
            ]);
        }

        $user->email_verified_at = now();
        $user->save();

        return redirect()->to(AdminMenu::homeUrl($user));
    }

    public function resend(Request $request, AuthEmailCode $codes): RedirectResponse
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return redirect()->to(AdminMenu::homeUrl($user));
        }

        $codes->send($user->email, AuthEmailCode::PURPOSE_EMAIL_VERIFY);

        return back()->with('status', 'Код отправлен на '.$user->email);
    }
}
