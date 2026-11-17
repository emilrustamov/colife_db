<?php

namespace App\Http\Middleware;

use App\Support\AdminMenu;
use Illuminate\Http\Request;
use Inertia\Middleware;

class InertiaShare extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'can' => [
                        'manageUsers' => $user->can('users.manage'),
                        'editUsers' => $user->can('users.edit'),
                        'deleteUsers' => $user->can('users.delete'),
                        'manageRoles' => $user->can('roles.manage'),
                        'editOlBotTemplates' => $user->can('ol-bot.templates.edit'),
                    ],
                ],
            ],
            'adminMenu' => fn () => AdminMenu::forUser($user),
        ];
    }
}
