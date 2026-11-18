<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuthEmailCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function store(Request $request, AuthEmailCode $codes): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = mb_strtolower(trim($validated['email']));

        // Same response whether the user exists — avoid email enumeration.
        if (User::query()->where('email', $email)->exists()) {
            $codes->send($email, AuthEmailCode::PURPOSE_PASSWORD_RESET);
        }

        return redirect()
            ->route('password.reset')
            ->with('status', 'Если аккаунт с таким email есть, мы отправили код подтверждения.')
            ->with('reset_email', $email);
    }

    public function edit(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => (string) ($request->session()->get('reset_email') ?? old('email', '')),
        ]);
    }

    public function update(Request $request, AuthEmailCode $codes): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $email = mb_strtolower(trim($validated['email']));

        if (! $codes->verify($email, AuthEmailCode::PURPOSE_PASSWORD_RESET, $validated['code'])) {
            return back()->withErrors([
                'code' => 'Неверный или просроченный код.',
            ])->withInput($request->only('email'));
        }

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            return back()->withErrors([
                'email' => 'Пользователь не найден.',
            ]);
        }

        $user->password = $validated['password'];
        $user->save();

        return redirect()
            ->route('login')
            ->with('status', 'Пароль обновлён. Можно войти.');
    }
}
